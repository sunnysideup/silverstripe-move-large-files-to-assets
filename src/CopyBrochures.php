<?php

namespace Sunnysideup\MoveLargeFilesToAssets;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;

class CopyBrochures extends BuildTask
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
        $folderName = self::config()->get('folder_name');
        $oldPath = Controller::join_links(ASSETS_PATH, '..', $folderName);
        $newPath = Controller::join_links(ASSETS_PATH, $folderName);
        if (! file_exists($newPath)) {
            if (file_exists($oldPath)) {
                DB::alteration_message('Moved ' . $oldPath . ' to ' . $newPath . '');
                rename($oldPath, $newPath);
            } else {
                DB::alteration_message('Could not move ' . $oldPath . ' to ' . $newPath . ' because ' . $oldPath . ' does not exist.');
            }
        } else {
            DB::alteration_message('Could not move ' . $oldPath . ' to ' . $newPath . ' because ' . $newPath . ' already exists.');
        }
        $this->addToDb($newPath);
    }

    public function addToDb(string $newFolderPath)
    {
        DB::alteration_message('scanning ' . $newFolderPath);
        $paths = scandir($newFolderPath);
        if ($paths && is_array($paths) && count($paths) < 100) {
            foreach ($paths as $path) {
                $path = Controller::join_links($newFolderPath, $path);
                DB::alteration_message('considering ' . $path);
                if (! is_dir($path)) {
                    $fileName = basename($path);
                    $folderPath = dirname($path);
                    $folder = Folder::find_or_make($folderPath);
                    $filter = ['Name' => $fileName, 'ParentID' => $folder->ID];
                    $file = File::get()->filter($filter)->first();
                    if (! $file) {
                        DB::alteration_message('New file: ' . $path);
                        $file = File::create();
                        $file->setFromLocalFile($path);
                        $file->ParentID = $folder->ID;
                        $file->write();
                    } else {
                        DB::alteration_message('existing file: ' . $path);
                    }
                } else {
                    DB::alteration_message('skipping ' . $path);
                }
            }
        } else {
            DB::alteration_message('nothing to add');
        }
    }
}
