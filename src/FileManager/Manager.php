<?php

namespace FileManager;

use Google\Cloud\Storage\StorageClient;

class Manager {

    public $_config = false;
    public $_storage = false;

    public function __construct($config) {
        $this->_config = $config;

        $this->_storage = new StorageClient([
            'projectId' => $this->_config->project_id,
            'keyFile' => json_decode(json_encode($this->_config), true)
        ]);
    }

    public function getFiles($bucketName) {
        $bucket = $this->_storage->bucket($bucketName);
        $files = $bucket->objects();
        
        return $files;
    }

    public function checkFile($projectName, $filePath, $mode="standard") {
        $project = new Project($this->_config);
        $project->createProject($projectName);

        $bucketName = $project->getBucketName($projectName);
        if ($mode === "export") $bucketName = $project->getBucketName($projectName, "export");

        if (substr($filePath, 0, 1) === "/") $filePath = substr($filePath, 1);

        $bucket = $this->_storage->bucket($bucketName);
        $object = $bucket->object($filePath);
        $info = $object->info();
        if (isset($info['name'])) {
            printf('Blob: %s' . PHP_EOL, $info['name']);
        }
        if (isset($info['bucket'])) {
            printf('Bucket: %s' . PHP_EOL, $info['bucket']);
        }
        if (isset($info['storageClass'])) {
            printf('Storage class: %s' . PHP_EOL, $info['storageClass']);
        }
        if (isset($info['id'])) {
            printf('ID: %s' . PHP_EOL, $info['id']);
        }
        if (isset($info['size'])) {
            printf('Size: %s' . PHP_EOL, $info['size']);
        }
        if (isset($info['updated'])) {
            printf('Updated: %s' . PHP_EOL, $info['updated']);
        }
        if (isset($info['generation'])) {
            printf('Generation: %s' . PHP_EOL, $info['generation']);
        }
        if (isset($info['metageneration'])) {
            printf('Metageneration: %s' . PHP_EOL, $info['metageneration']);
        }
        if (isset($info['etag'])) {
            printf('Etag: %s' . PHP_EOL, $info['etag']);
        }
        if (isset($info['crc32c'])) {
            printf('Crc32c: %s' . PHP_EOL, $info['crc32c']);
        }
        if (isset($info['md5Hash'])) {
            printf('MD5 Hash: %s' . PHP_EOL, $info['md5Hash']);
        }
        if (isset($info['contentType'])) {
            printf('Content-type: %s' . PHP_EOL, $info['contentType']);
        }
        if (isset($info['temporaryHold'])) {
            printf('Temporary hold: %s' . PHP_EOL, ($info['temporaryHold'] ? 'enabled' : 'disabled'));
        }
        if (isset($info['eventBasedHold'])) {
            printf('Event-based hold: %s' . PHP_EOL, ($info['eventBasedHold'] ? 'enabled' : 'disabled'));
        }
        if (isset($info['retentionExpirationTime'])) {
            printf('Retention Expiration Time: %s' . PHP_EOL, $info['retentionExpirationTime']);
        }
        if (isset($info['customTime'])) {
            printf('Custom Time: %s' . PHP_EOL, $info['customTime']);
        }
        if (isset($info['metadata'])) {
            printf('Metadata: %s' . PHP_EOL, print_r($info['metadata'], true));
        }
    }

    public function browse($projectName, $filePath="", $mode="standard") {
        $path = __DIR__."/../../storage/metadata";
        if (!file_exists($path)) mkdir($path, 0777, true);

        $metadata = $path."/".\strtolower($projectName).".json";
        if ($mode === "export") $metadata = $path."/".\strtolower($projectName)."-export.json";
        if (file_exists($metadata)) $metadataContent = json_decode(file_get_contents($metadata), true);
        else $metadataContent = array("files" => array());

        //parsing content
        if ($filePath === "" || $filePath === "/") {
            $selectedFolder = $metadataContent;
        } else {
            $filePath = $this->buildPath($filePath);
            $selectedFolder = $this->accessingPath($metadataContent, $filePath);
        }

        $contents = array();
        $contents["files"] = $selectedFolder["files"];

        unset($selectedFolder["files"]);
        $folders = array();
        foreach ($selectedFolder as $key => $data) $folders[] = $key;
        $contents["folders"] = $folders;
        
        return $contents;
    }

    //todo: add folder and remove folder & its content(s)

    public function getFile($projectName, $filePath, $mode="standard") {
        $data = array();

        try {
            $projectName = \strtolower($projectName);

            $project = new Project($this->_config);
            $project->createProject($projectName);
    
            $bucketName = $project->getBucketName($projectName);
            if ($mode === "export") $bucketName = $project->getBucketName($projectName, "export");
    
            if (substr($filePath, 0, 1) === "/") $filePath = substr($filePath, 1);
    
            $try = 1;
            do {
                $continue = false;

                try {
                    $bucket = $this->_storage->bucket($bucketName);
                    $object = $bucket->object($filePath);
                    $info = $object->info();
                } catch (\Exception $e) {
                    $error = json_decode($e->getMessage());
                    if ($error->error->code !== 404) {
                        throw new \Exception('File not exist.');
                    } else {
                        if ($try >= 5) $this->deleteMetadata($projectName, $filePath);
                        else {
                            \sleep(1);
                            $continue = true;
                        }
                    }
                }
                $try++;
            } while ($continue);

            $data = array(
                "name" => $info["name"],
                "contentType" => $info["contentType"],
                "mediaLink" => $info["mediaLink"],
                "size" => $info["size"],
                "createdDate" => \strtotime($info["timeCreated"]),
                "lastUpdatedDate" => \strtotime($info["updated"]),
                "url" => $project->generateUrl($projectName, $info["name"])
            );
        } catch (\Exception $e) {
            throw new \Exception('File not exist.');
        }
        
        return $data;
    }

    public function deleteFile($projectName, $filePath, $mode="standard", $manipulation=true) {
        try {
            if ($manipulation) {
                if (substr($filePath, 0, 1) === "/") $filePath = substr($filePath, 1);

                if ($filePath !== "/") {
                    if (substr($filePath, 0, 1) === "/") $filePath = substr($filePath, 1);
                } else $filePath = "";
            }

            $projectName = \strtolower($projectName);

            $project = new Project($this->_config);
            $bucketName = $project->getBucketName($projectName);
            if ($mode === "export") $bucketName = $project->getBucketName($projectName, "export");

            $bucket = $this->_storage->bucket($bucketName);
            try {
                $object = $bucket->object($filePath);
                $object->delete();
            } catch (\Exception $e) {
                $error = json_decode($e->getMessage());
                if ($error->error->code !== 404) {
                    throw new \Exception('File not exist.');
                }
            }

            //update metadata
            $this->deleteMetadata($projectName, $filePath);
        } catch (\Exception $e) {
            throw new \Exception('File not exist.');
        }
        
        return true;
    }

    public function deleteMetadata($projectName, $filePath, $mode="standard") {
        //update metadata
        $path = __DIR__."/../../storage/metadata";
        if (!file_exists($path)) mkdir($path, 0777, true);

        $metadata = $path."/".$projectName.".json";
        if ($mode === "export") $metadata = $path."/".$projectName."-export.json";
        if (file_exists($metadata)) $metadataContent = json_decode(file_get_contents($metadata), true);
        else $metadataContent = array("files" => array());

        //parsing content
        if ($filePath === "" || $filePath === "/") {
            throw new \Exception('Unable to delete root folder.');
        } else {
            $filename = "";
            if (strpos($filePath, '/') !== false) {
                $pathParts = explode('/', $filePath);

                do {
                    if(empty($pathParts)) break;
                    $firstElement = array_pop($pathParts);
                    if ($firstElement !== "") $filename = $firstElement;
                } while ($firstElement === "");
                
                $filePath = $this->buildPath($filePath, $pathParts);
            } else {
                $filename = $filePath;
                $filePath = null;
            }

            $metadataContent = $this->removingPath($metadataContent, $filePath, $filename);
            $metadataContent = json_encode($metadataContent);
            file_put_contents($metadata, $metadataContent);
        }

        return true;
    }

    public function uploadFile($projectName, $filePath, $targetPath, $targetFilename, $mode="standard") {
        $project = new Project($this->_config);
        $project->createProject($projectName);

        $bucketName = $project->getBucketName($projectName);
        if ($mode === "export") $bucketName = $project->getBucketName($projectName, "export");
        if (!file_exists($filePath)) throw new \Exception('File not exist.');
        
        $path = __DIR__."/../../storage/metadata";
        if (!file_exists($path)) mkdir($path, 0777, true);

        $metadata = $path."/".\strtolower($projectName).".json";
        if ($mode === "export") $metadata = $path."/".\strtolower($projectName)."-export.json";
        if (file_exists($metadata)) $metadataContent = json_decode(file_get_contents($metadata), true);
        else $metadataContent = array("files" => array());

        $targetPathRaw = $targetPath;
        if ($targetPath === "" || $targetPath === "/") {
            $targetPathRaw = "/";
            // if (in_array($targetFilename, $metadataContent["files"])) throw new \Exception('File already exist at cloud, delete first.');
            if (!in_array($targetFilename, $metadataContent["files"])) $metadataContent["files"][] = $targetFilename;
        } else {
            $targetPath = $this->buildPath($targetPath);
            $metadataContent = $this->processingPath($metadataContent, $targetPath, $targetFilename);
        }

        if ($targetPathRaw !== "/") {
            if (substr($targetPathRaw, 0, 1) === "/") $targetPathRaw = substr($targetPathRaw, 1);
            if (substr($targetPathRaw, -1) !== "/") $targetPathRaw = $targetPathRaw."/";
        } else $targetPathRaw = "";

        $file = fopen($filePath, 'r');
        $objectName = $targetPathRaw.$targetFilename;
        $bucket = $this->_storage->bucket($bucketName);
        $object = $bucket->upload($file, ['name' => $objectName]);
        $object->update(['acl' => []], ['predefinedAcl' => 'PUBLICREAD']);

        $metadataContent = json_encode($metadataContent);
        file_put_contents($metadata, $metadataContent);

        $url = $project->generateUrl($projectName, $targetPathRaw.$targetFilename);
        return $url;
    }

    public function deletingFilesAtFolder($projectName, $metadataContent, $mode="standard", $recursive=false, $path="") {
        $files = $metadataContent["files"];
        foreach ($files as $file) {
            try {
                $this->deleteFile($projectName, $path."/".$file, $mode);
            } catch (\Exception $e) {
                continue;
            }
        }

        if ($recursive === true) {
            foreach ($metadataContent as $key => $content) {
                if ($key === "files") continue;
                $path = $path."/".$key;
                $this->deletingFilesAtFolder($projectName, $content, $mode, $recursive, $path);
            }
        }

        return true;
    }

    public function deletingFilesAtBucket($projectName, $bucketName, $mode="standard") {
        $files = $this->getFiles($bucketName);
        foreach ($files as $file) {
            try {
                $this->deleteFile($projectName, $file->name(), $mode, false);
            } catch (\Exception $e) {
                continue;
            }
        }

        return true;
    }

    protected function accessingPath($metadataContent, $targetPath) {
        $selectedFolder = false;

        foreach ($targetPath as $key => $path) {
            if ($key == "0") {
                if (!array_key_exists($path, $metadataContent)) throw new \Exception('Wrong path.');
                $selectedFolder = $metadataContent[$path];
            } else {
                if (!array_key_exists($key, $metadataContent)) throw new \Exception('Wrong path.');
                $selectedFolder = $this->accessingPath($metadataContent[$key], $path);
            }
        }

        return $selectedFolder;
    }

    protected function processingPath($metadataContent, $targetPath, $targetFilename) {
        foreach ($targetPath as $key => $path) {
            if ($key == "0") {
                if (!array_key_exists($path, $metadataContent)) $metadataContent[$path] = array("files" => array());
                // if (in_array($targetFilename, $metadataContent[$path]["files"])) throw new \Exception('File already exist at cloud, delete first.');
                if (!in_array($targetFilename, $metadataContent[$path]["files"])) $metadataContent[$path]["files"][] = $targetFilename;
            } else {
                if (!array_key_exists($key, $metadataContent)) $metadataContent[$key] = array("files" => array());
                $metadataContent[$key] = $this->processingPath($metadataContent[$key], $path, $targetFilename);
            }
        }

        return $metadataContent;
    }

    protected function removingPath($metadataContent, $targetPath, $filename) {
        if ($targetPath) {
            foreach ($targetPath as $key => $path) {
                if ($key == "0") {
                    if (!array_key_exists($path, $metadataContent)) throw new \Exception('Wrong path.');
                    if (($pos = array_search($filename, $metadataContent[$path]["files"])) !== false) unset($metadataContent[$path]["files"][$pos]);
                } else {
                    if (!array_key_exists($key, $metadataContent)) throw new \Exception('Wrong path.');
                    $metadataContent[$key] = $this->removingPath($metadataContent[$key], $path, $filename);
                }
            }
        } else {
            if (($pos = array_search($filename, $metadataContent["files"])) !== false) unset($metadataContent["files"][$pos]);
        }

        return $metadataContent;
    }

    protected function buildPath($pathString, $pathParts=false) {
        if (!$pathParts) $pathParts = explode('/', $pathString);
    
        do {
            if(empty($pathParts)) break;
            $firstElement = array_pop($pathParts);
            if ($firstElement !== "") $path = [$firstElement];
        } while ($firstElement === "");

        foreach (array_reverse($pathParts) as $pathPart) {
            if ($pathPart === "") continue;
            $path = [$pathPart => $path];
        }
        
        return $path;
    }
}