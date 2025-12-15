<?php

namespace FileManager;

class Metadata
{
    //Tester
    public function buildPath($pathString, $pathParts = false)
    {
        if ($pathString === "/") $pathString = "";
        if ($pathString === "") return false;
        if (!$pathParts) $pathParts = explode('/', $pathString);

        do {
            if (empty($pathParts)) break;
            $firstElement = array_pop($pathParts);
            if ($firstElement !== "") $path = [$firstElement];
        } while ($firstElement === "");

        foreach (array_reverse($pathParts) as $pathPart) {
            if ($pathPart === "") continue;
            $path = [$pathPart => $path];
        }

        return $path;
    }

    protected function accessingPath($metadataContent, $targetPath)
    {
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

    protected function checkPath($metadataContent, $buildPath, $exist = true)
    {
        foreach ($buildPath as $key => $path) {
            if ($key == "0") {
                if (!array_key_exists($path, $metadataContent)) return false;
            } else {
                if (!array_key_exists($key, $metadataContent)) return false;
                $exist = $this->checkPath($metadataContent[$key], $path, $exist);
            }
        }

        return $exist;
    }

    protected function removePath($metadataContent, $buildPath, $filename = false)
    {
        if ($buildPath) {
            foreach ($buildPath as $key => $path) {
                if ($key == "0") {
                    if (!array_key_exists($path, $metadataContent)) throw new \Exception('Wrong path.');
                    if ($filename) {
                        if (($pos = array_search($filename, $metadataContent[$path]["files"])) !== false) unset($metadataContent[$path]["files"][$pos]);
                    } else {
                        unset($metadataContent[$path]);
                    }
                } else {
                    if (!array_key_exists($key, $metadataContent)) throw new \Exception('Wrong path.');
                    $metadataContent[$key] = $this->removePath($metadataContent[$key], $path, $filename);
                }
            }
        } else {
            if ($filename) {
                if (($pos = array_search($filename, $metadataContent["files"])) !== false) unset($metadataContent["files"][$pos]);
            } else {
                unset($metadataContent[$buildPath]);
            }
        }

        return $metadataContent;
    }

    protected function processingPath($metadataContent, $buildPath, $targetFilename = false)
    {
        foreach ($buildPath as $key => $path) {
            if ($key == "0") {
                if (!is_array($metadataContent)) {
                    $metadataContent = json_decode(json_encode($metadataContent), true);
                }
                if (!array_key_exists($path, $metadataContent)) $metadataContent[$path] = array("files" => array());
                if ($targetFilename) {
                    if (in_array($targetFilename, $metadataContent[$path]["files"])) throw new \Exception('File already exist at cloud, delete first.');
                    $metadataContent[$path]["files"][] = $targetFilename;
                }
            } else {
                if (!array_key_exists($key, $metadataContent)) $metadataContent[$key] = array("files" => array());
                $metadataContent[$key] = $this->processingPath($metadataContent[$key], $path, $targetFilename);
            }
        }

        return $metadataContent;
    }

    public function getProjectMeta($projectName)
    {
        $projectName = \strtolower($projectName);

        $path = __DIR__ . "/../../storage/metadata/";
        if (!file_exists($path)) mkdir($path, 0777, true);

        //delete files first before deleting bucket
        $metadata = $path . \strtolower($projectName) . ".json";

        if (file_exists($metadata)) {
            $metadataContent = json_decode(file_get_contents($metadata), true);
        } else {
            $metadataContent = array("files" => array());

            $metadataContent = json_encode($metadataContent);
            file_put_contents($metadata, $metadataContent);
            $metadataContent = json_decode($metadataContent);
        }

        return $metadataContent;
    }

    public function checkFolderMeta($projectName, $path)
    {
        $projectName = \strtolower($projectName);
        $metadataContent = $this->getProjectMeta($projectName);
        if ($path === "" || $path === "/") return true;

        $buildPath = $this->buildPath($path);
        $exist = $this->checkPath($metadataContent, $buildPath);

        return $exist;
    }

    public function createFolderMeta($projectName, $path)
    {
        $projectName = \strtolower($projectName);
        $metadata = __DIR__ . "/../../storage/metadata/" . $projectName . ".json";
        $metadataContent = $this->getProjectMeta($projectName);
        if ($path === "" || $path === "/") return $metadataContent;

        $buildPath = $this->buildPath($path);
        $metadataContent = $this->processingPath($metadataContent, $buildPath);

        $metadataContent = json_encode($metadataContent);
        file_put_contents($metadata, $metadataContent);
        $metadataContent = json_decode($metadataContent);

        return $metadataContent;
    }

    public function deleteFolderMeta($projectName, $path)
    {
        $projectName = \strtolower($projectName);
        $metadata = __DIR__ . "/../../storage/metadata/" . $projectName . ".json";
        $metadataContent = $this->getProjectMeta($projectName);
        if ($path === "" || $path === "/") return $metadataContent;

        $buildPath = $this->buildPath($path);
        $metadataContent = $this->removePath($metadataContent, $buildPath);

        $metadataContent = json_encode($metadataContent);
        file_put_contents($metadata, $metadataContent);
        $metadataContent = json_decode($metadataContent);

        return $metadataContent;
    }

    public function browse($projectName, $filePath = "", $mode = "standard")
    {
        $projectName = \strtolower($projectName);

        $path = __DIR__ . "/../../storage/metadata";
        if (!file_exists($path)) mkdir($path, 0777, true);

        $metadata = $path . "/" . $projectName . ".json";
        if ($mode === "export") $metadata = $path . "/" . $projectName . "-export.json";
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
}
