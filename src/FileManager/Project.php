<?php

namespace FileManager;

use Google\Cloud\Storage\StorageClient;

class Project {

    public $_config = false;
    public $_storage = false;

    public function __construct($config) {
        $this->_config = $config;

        $this->_storage = new StorageClient([
            'projectId' => $this->_config->project_id,
            'keyFile' => json_decode(json_encode($this->_config), true)
        ]);
    }

    public function generateUrl($projectName, $targetFilename) {
        $bucketName = $this->getBucketName($projectName);
        $url = "https://storage.googleapis.com/".$bucketName."/".$targetFilename;

        return $url;
    }

    public function getBucketName($projectName, $extra=false) {
        $bucketName = $this->_config->project_id."-".$projectName;
        if ($extra) $bucketName .= "-".$extra;

        return $bucketName;
    }

    public function checkBucket($bucketName) {
        try {
            $bucket = $this->_storage->bucket($bucketName);

            return $bucket->info();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function deleteBucket($bucketName) {
        $bucket = $this->_storage->bucket($bucketName);
        $bucket->delete();
    }

    function listBuckets() {
        foreach ($this->_storage->buckets() as $bucket) printf('Bucket: %s' . PHP_EOL, $bucket->name());
    }

    public function getBucketMetadata($bucketName) {
        $bucket = $this->_storage->bucket($bucketName);
        $info = $bucket->info();
        return $info;
    }

    public function addLabelToBucket($bucketName, $labelName, $labelValue) {
        $bucket = $this->_storage->bucket($bucketName);
        $newLabels = [$labelName => $labelValue];
        $bucket->update(['labels' => $newLabels]);
    }

    public function removeLabelFromBucket($bucketName, $labelName) {
        $bucket = $this->_storage->bucket($bucketName);
        $labels = [$labelName => null];
        $bucket->update(['labels' => $labels]);
    }

    public function listProject() {
        $path = __DIR__."/../../storage";
        if (!file_exists($path)) mkdir($path, 0777, true);

        $file = $path."/projects.json";
        if (file_exists($file)) $content = json_decode(file_get_contents($file), true);
        else $content = array();

        printf('Projects:' . PHP_EOL);
        foreach ($content as $projectName) printf('- %s' . PHP_EOL, $projectName);

        return true;
    }

    public function createProject($projectName) {
        $bucketName = $this->getBucketName($projectName);
        if (!$this->checkBucket($bucketName)) {
            $bucket = $this->_storage->createBucket($bucketName);
            $this->addLabelToBucket($bucket->name(), "tag", "standard");

            $bucketName = $this->getBucketName($projectName, "export");
            if ($this->checkBucket($bucketName)) $this->deleteBucket($bucketName);

            $bucket = $this->_storage->createBucket($bucketName, array("storageClass" => "NEARLINE"));
            $this->addLabelToBucket($bucket->name(), "tag", "report");
            
            $path = __DIR__."/../../storage";
            if (!file_exists($path)) mkdir($path, 0777, true);

            $file = $path."/projects.json";
            if (file_exists($file)) $content = json_decode(file_get_contents($file), true);
            else $content = array();

            if (!in_array($projectName, $content)) $content[] = $projectName;

            $content = json_encode($content);
            file_put_contents($file, $content);
        }

        return true;
    }

    public function deleteProject($projectName) {
        $manager = new Manager($this->_config);

        //delete files first before deleting bucket
        $bucketName = $this->getBucketName($projectName);
        $metadata = __DIR__."/../../storage/metadata/".\strtolower($projectName).".json";
        if (file_exists($metadata)) {
            $metadataContent = json_decode(file_get_contents($metadata), true);
            $manager->deletingFilesAtFolder($projectName, "standard", $metadataContent, true);
            unlink($metadata);
        }
        $manager->deletingFilesAtBucket($projectName, "standard", $bucketName);

        if ($this->checkBucket($bucketName)) $this->deleteBucket($bucketName);

        $bucketName = $this->getBucketName($projectName, "export");
        $metadata = __DIR__."/../../storage/metadata/".\strtolower($projectName)."-export.json";
        if (file_exists($metadata)) {
            $metadataContent = json_decode(file_get_contents($metadata), true);
            $manager->deletingFilesAtFolder($projectName, "export", $metadataContent, true);
            unlink($metadata);
        }
        $manager->deletingFilesAtBucket($projectName, "export", $bucketName);
        if ($this->checkBucket($bucketName)) $this->deleteBucket($bucketName);
            
        $path = __DIR__."/../../storage";
        if (!file_exists($path)) mkdir($path, 0777, true);

        $file = $path."/projects.json";
        if (file_exists($file)) {
            $content = json_decode(file_get_contents($file), true);
            if (($pos = array_search($projectName, $content)) !== false) unset($content[$pos]);

            $content = json_encode($content);
            file_put_contents($file, $content);
        }

        return true;
    }
}