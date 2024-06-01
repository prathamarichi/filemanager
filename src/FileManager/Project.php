<?php

namespace FileManager;

use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;

class Project
{

    public $_config = false;
    public $_storage = false;

    public function __construct($config)
    {
        $this->_config = $config;

        $this->_storage = new StorageClient([
            'projectId' => $this->_config->project_id,
            'keyFile' => json_decode(json_encode($this->_config), true)
        ]);
    }

    public function generateUrl($projectName, $targetFilename, $mode = "standard")
    {
        $bucketName = $this->getBucketName($projectName);
        if ($mode == "standard") {
            $url = "https://storage.googleapis.com/" . $bucketName . "/" . $targetFilename;
        } else {
            $url = "https://storage.googleapis.com/" . $bucketName . "-" . $mode . "/" . $targetFilename;
        }

        return $url;
    }

    public function getBucketName($projectName, $extra = false)
    {
        $bucketName = $this->_config->project_id . "-" . $projectName;
        if ($extra) $bucketName .= "-" . $extra;

        return $bucketName;
    }

    public function checkBucket($bucketName)
    {
        try {
            $bucket = $this->_storage->bucket($bucketName);

            return $bucket->info();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function deleteBucket($bucketName)
    {
        $bucket = $this->_storage->bucket($bucketName);
        $bucket->delete();
    }

    function listBuckets()
    {
        foreach ($this->_storage->buckets() as $bucket) printf('Bucket: %s' . PHP_EOL, $bucket->name());
    }


    public function getBucketMetadata($bucketName)
    {
        $bucket = $this->_storage->bucket($bucketName);
        $info = $bucket->info();
        return $info;
    }

    public function addLabelToBucket($bucketName, $labelName, $labelValue)
    {
        $bucket = $this->_storage->bucket($bucketName);
        $newLabels = [$labelName => $labelValue];
        $bucket->update(['labels' => $newLabels]);
    }

    public function removeLabelFromBucket($bucketName, $labelName)
    {
        $bucket = $this->_storage->bucket($bucketName);
        $labels = [$labelName => null];
        $bucket->update(['labels' => $labels]);
    }

    public function listProject()
    {
        $path = __DIR__ . "/../../storage";
        if (!file_exists($path)) mkdir($path, 0777, true);

        $file = $path . "/projects.json";
        if (file_exists($file)) $content = json_decode(file_get_contents($file), true);
        else $content = array();

        printf('Projects:' . PHP_EOL);
        foreach ($content as $projectName) printf('- %s' . PHP_EOL, $projectName);

        return true;
    }

    public function reinitiateProject()
    {
        $listBuckets = $this->_storage->buckets();
        $projects = array();
        foreach ($listBuckets as $bucket) {
            $bucketName = \str_replace($this->_config->project_id . '-', "", $bucket->name());

            if (\str_contains($bucketName, 'export') || \str_contains($bucketName, 'transaction')) {
                continue;
            }

            if (\strlen($bucketName) != 3) {
                continue;
            }

            $projects[] = $bucketName;
        }

        foreach ($projects as $projectName) {
            $bucketName = $this->getBucketName($projectName);
            if (!$this->checkBucket($bucketName)) {
                $bucket = $this->_storage->createBucket($bucketName);
                $this->addLabelToBucket($bucket->name(), "tag", "standard");
            }

            $bucketName = $this->getBucketName($projectName, "export");
            if (!$this->checkBucket($bucketName)) {
                $bucket = $this->_storage->createBucket($bucketName, array("storageClass" => "NEARLINE"));
                $this->addLabelToBucket($bucket->name(), "tag", "report");
            } else {
                $bucket = $this->_storage->bucket($bucketName);
            }

            $lifecycle = Bucket::lifecycle()
                ->addDeleteRule([
                    'age' => 7
                ]);

            $bucket->update([
                'lifecycle' => $lifecycle
            ]);

            $bucketName = $this->getBucketName($projectName, "transaction");
            if (!$this->checkBucket($bucketName)) {
                $bucket = $this->_storage->createBucket($bucketName, array("storageClass" => "NEARLINE"));

                $this->addLabelToBucket($bucket->name(), "tag", "transaction");
            } else {
                $bucket = $this->_storage->bucket($bucketName);
            }

            $lifecycle = Bucket::lifecycle()
                ->addDeleteRule([
                    'age' => 7
                ]);

            $bucket->update([
                'lifecycle' => $lifecycle
            ]);
        }

        return true;
    }

    public function createProject($projectName)
    {
        $bucketName = $this->getBucketName($projectName);
        if (!$this->checkBucket($bucketName)) {
            $bucket = $this->_storage->createBucket($bucketName);
            $this->addLabelToBucket($bucket->name(), "tag", "standard");

            $bucketName = $this->getBucketName($projectName, "export");
            if ($this->checkBucket($bucketName)) $this->deleteBucket($bucketName);

            $bucket = $this->_storage->createBucket($bucketName, array("storageClass" => "NEARLINE"));
            $lifecycle = Bucket::lifecycle()
                ->addDeleteRule([
                    'age' => 7
                ]);

            $bucket->update([
                'lifecycle' => $lifecycle
            ]);
            $this->addLabelToBucket($bucket->name(), "tag", "report");

            $bucketName = $this->getBucketName($projectName, "transaction");
            if ($this->checkBucket($bucketName)) $this->deleteBucket($bucketName);

            $bucket = $this->_storage->createBucket($bucketName, array("storageClass" => "NEARLINE"));
            $lifecycle = Bucket::lifecycle()
                ->addDeleteRule([
                    'age' => 7
                ]);

            $bucket->update([
                'lifecycle' => $lifecycle
            ]);
            $this->addLabelToBucket($bucket->name(), "tag", "transaction");

            $path = __DIR__ . "/../../storage";
            if (!file_exists($path)) mkdir($path, 0777, true);

            $file = $path . "/projects.json";
            if (file_exists($file)) $content = json_decode(file_get_contents($file), true);
            else $content = array();

            if (!in_array($projectName, $content)) $content[] = $projectName;

            $content = json_encode($content);
            file_put_contents($file, $content);
        }

        $metadata = new Metadata();
        $metadataContent = $metadata->getProjectMeta($projectName);

        return $metadataContent;
    }

    public function deleteProject($projectName)
    {
        $manager = new Manager($this->_config);

        //delete files first before deleting bucket
        $bucketName = $this->getBucketName($projectName);
        $metadata = __DIR__ . "/../../storage/metadata/" . \strtolower($projectName) . ".json";
        if (file_exists($metadata)) {
            $metadataContent = json_decode(file_get_contents($metadata), true);
            $manager->deletingFilesAtFolder($projectName, $metadataContent, "standard", true);
            unlink($metadata);
        }
        $manager->deletingFilesAtBucket($projectName, $bucketName, "standard");

        if ($this->checkBucket($bucketName)) $this->deleteBucket($bucketName);

        $bucketName = $this->getBucketName($projectName, "export");
        $metadata = __DIR__ . "/../../storage/metadata/" . \strtolower($projectName) . "-export.json";
        if (file_exists($metadata)) {
            $metadataContent = json_decode(file_get_contents($metadata), true);
            $manager->deletingFilesAtFolder($projectName, $metadataContent, "export", true);
            unlink($metadata);
        }
        $manager->deletingFilesAtBucket($projectName, $bucketName, "export");
        if ($this->checkBucket($bucketName)) $this->deleteBucket($bucketName);

        $path = __DIR__ . "/../../storage";
        if (!file_exists($path)) mkdir($path, 0777, true);

        $file = $path . "/projects.json";
        if (file_exists($file)) {
            $content = json_decode(file_get_contents($file), true);
            if (($pos = array_search($projectName, $content)) !== false) unset($content[$pos]);

            $content = json_encode($content);
            file_put_contents($file, $content);
        }

        return true;
    }

    public function checkFolder($projectName, $path)
    {
        $path = strtolower($path);

        $metadata = new Metadata();
        $exist = $metadata->checkFolderMeta($projectName, $path);

        return $exist;
    }

    public function createFolder($projectName, $path)
    {
        $path = strtolower($path);

        $metadata = new Metadata();
        $metadataContent = $metadata->createFolderMeta($projectName, $path);

        return $metadataContent;
    }

    public function deleteFolder($projectName, $path)
    {
        $path = strtolower($path);

        $metadata = new Metadata();
        $exist = $metadata->checkFolderMeta($projectName, $path);
        if (!$exist) return false;

        $contents = $metadata->browse($projectName, $path);
        $contents = $this->deleteContents($projectName, $path, $contents);

        $metadataContent = $metadata->getProjectMeta($projectName);
        $metadataContent = $metadata->deleteFolderMeta($projectName, $path);

        return $metadataContent;
    }

    public function deleteContents($projectName, $path, $contents)
    {
        foreach ($contents["files"] as $file) {
            $filePath = $path . "/" . $file;
            $manager = new Manager($this->_config);
            $manager->deleteFile($projectName, $filePath);
        }

        foreach ($contents["folders"] as $folder) {
            $metadata = new Metadata();
            $innerPath = $path . "/" . $folder;
            $innerContents = $metadata->browse($projectName, $innerPath);
            $this->deleteContents($projectName, $innerPath, $innerContents);
        }

        return true;
    }
}
