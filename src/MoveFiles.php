<?php

namespace Sunnysideup\MoveLargeFilesToAssets;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;

class MoveFiles extends BuildTask
{
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
        $folderNameFromConfig = $this->config()->get('folder_name');
        $oldPathFromConfig = Controller::join_links(Director::baseFolder(), $folderNameFromConfig);
        $base = Director::baseFolder();
        $baseWithFolderNameFromConfig = Controller::join_links($base, $folderNameFromConfig);

        if(is_dir($oldPathFromConfig)) {
            $files = $this->getDirContents($oldPathFromConfig);
        } else {
            $files = [$oldPathFromConfig];
        }
        foreach($files as $oldPath) {
            $newRelativePath = str_replace($base, '', $oldPath);
            if(! $newRelativePath) {
                $newRelativePath = $folderNameFromConfig;
            }
            $newPath = Controller::join_links(ASSETS_PATH, $newRelativePath);
            $newRelativeFolderPath = ltrim(dirname($newRelativePath), '/');
            $newFolderPath = Controller::join_links(ASSETS_PATH, $newRelativeFolderPath);
            if(dirname($newPath) !== $newFolderPath) {
                user_error('error in logic');
                die('-----');
            }
            DB::alteration_message('base ' . $base);
            DB::alteration_message('Moving ' . $oldPath . ' to ' . $newPath . '');
            if (! file_exists($newPath)) {
                if (file_exists($oldPath)) {
                    DB::alteration_message('Creating Dir: ' . $newRelativeFolderPath);
                    Folder::find_or_make($newRelativeFolderPath);
                    if(file_exists($newFolderPath)) {
                        DB::alteration_message('Moving ' . $oldPath . ' to ' . $newPath . '');
                        cp($oldPath, $newPath);
                        $this->addToDb($newPath);
                    } else {
                        DB::alteration_message('Could not create dir: '.$newFolderPath);
                    }
                } else {
                    DB::alteration_message('Could not move ' . $oldPath . ' to ' . $newPath . ' because ' . $oldPath . ' does not exist.');
                }
            } else {
                DB::alteration_message('Could not move ' . $oldPath . ' to ' . $newPath . ' because ' . $newPath . ' already exists.');
            }
        }
    }

    public function addToDb(string $newPath)
    {
        DB::alteration_message('considering ' . $newPath);
        if (! is_dir($newPath)) {
            $fileName = basename($newPath);
            $folderPath = dirname($newPath);
            $folder = Folder::find_or_make($folderPath);
            $filter = ['Name' => $fileName, 'ParentID' => $folder->ID];
            $file = File::get()->filter($filter)->first();
            if (! $file) {
                DB::alteration_message('New file: ' . $newPath);
                $file = File::create();
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

    protected static function normalizePath($path)
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
