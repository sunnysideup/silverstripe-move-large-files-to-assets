<?php

namespace Sunnysideup\MoveLargeFilesToAssets;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Folder;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;

use SilverStripe\Dev\Tasks\MigrateFileTask;
use SilverStripe\ORM\DB;

class MoveFiles extends MigrateFileTask
{

    private static $segment = 'MoveFiles';

    /**
     * {@inheritDoc}
     */
    protected $title = 'Copy (large) files to assets';

    /**
     * {@inheritDoc}
     */
    protected $description = 'Move public/new-files to assets/new-files where new-files can be set as you see fit.';

    /**
     * {@inheritDoc}
     */
    protected $enabled = true;

    private static $folder_name = 'new-files';
    /**
     * {@inheritDoc}
     */
    public function run($request)
    {
        $folderNameFromConfig = self::config()->get('folder_name');
        $oldPathFromConfig = Controller::join_links(Director::baseFolder(), $folderNameFromConfig);
        $base = Director::baseFolder();
        $baseWithFolderNameFromConfig = Controller::join_links($base, $folderNameFromConfig);

        if(is_dir($oldPathFromConfig)) {
            $newPath = Controller::join_links(ASSETS_PATH, $folderNameFromConfig);
            DB::alteration_message('copying '.$oldPathFromConfig.' to '.$newPath);
            $this->rcopy($oldPathFromConfig, $newPath);
            $this->registerFiles($newPath);
            $this->defaultSubtasks = [
                'move-files',
                'migrate-folders',
                'generate-cms-thumbnails',
                'fix-folder-permissions',
                'fix-secureassets',
                'normalise-access',
            ];
            parent::run($request);
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function registerFiles($newPath)
    {
        $files = $this->getDirContents($newPath);
        foreach($files as $newPath) {
            $this->addToDb($newPath);
        }
    }

    protected function addToDb(string $newPath)
    {
        DB::alteration_message('considering ' . $newPath);
        if (! is_dir($newPath)) {
            $fileName = basename($newPath);
            $folderPath = str_replace(ASSETS_PATH . '/', '', dirname($newPath));
            $folder = Folder::find_or_make($folderPath);
            $filter = ['Name' => $fileName, 'ParentID' => $folder->ID];
            $file = File::get()->filter($filter)->exists();
            if (! $file) {
                if($this->isImage($newPath)) {
                    DB::alteration_message('New IMAGE!: ' . $newPath);
                    $file = Image::create();
                } else {
                    DB::alteration_message('New FILE!: ' . $newPath);
                    $file = File::create();
                }
                $file->setFromLocalFile($newPath);
                $file->ParentID = $folder->ID;
                $file->write();
                $file->doPublish();
            } else {
                DB::alteration_message('existing file: ' . $newPath);
            }
        }
    }

    protected function getDirContents(string $dir, ?array &$results = []) {
        $files = scandir($dir);

        foreach ($files as $key => $value) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
            if (!is_dir($path)) {
                $results[] = $path;
            } else if ($value != "." && $value != "..") {
                $this->getDirContents($path, $results);
                $results[] = $path;
            }
        }

        return $results;
    }

    protected function isImage(string $newPath) : bool
    {
        $ext = pathinfo(
            parse_url($newPath, PHP_URL_PATH),
            PATHINFO_EXTENSION
        );
        if(in_array($ext, ['jpeg', 'jpg', 'gif', 'png'])) {
            return true;
        }
        return false;
    }


    protected function normalizePath($path)
    {
        return $path.(is_dir($path) && !preg_match('@/$@', $path) ? '/' : '');
    }

    protected function rscandir($dir, $sort = SCANDIR_SORT_ASCENDING)
    {
        $results = array();

        if(!is_dir($dir)) {
            return $results;
        }

        $dir = $this->normalizePath($dir);

        $objects = scandir($dir, $sort);

        foreach($objects as $object) {
            if($object != '.' && $object != '..')
            {
                if(is_dir($dir.$object)) {
                    $results = array_merge($results, $this->rscandir($dir.$object, $sort));
                }  else {
                    array_push($results, $dir.$object);
                }
            }
        }

        array_push($results, $dir);

        return $results;
    }

    protected function rcopy($source, $dest, $destmode = null)
    {
        $files = $this->rscandir($source);

        if(empty($files)) {
            return;
        }

        if(!file_exists($dest)) {
            mkdir($dest, is_int($destmode) ? $destmode : fileperms($source), true);
        }

        $source = $this->normalizePath(realpath($source));
        $dest = $this->normalizePath(realpath($dest));

        foreach($files as $file)
        {
            $file_dest = str_replace($source, $dest, $file);

            if(is_dir($file))
            {
                if(!file_exists($file_dest))
                mkdir($file_dest, is_int($destmode) ? $destmode : fileperms($file), true);
            }
            else {
                copy($file, $file_dest);
            }
        }
    }
}
