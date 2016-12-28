<?php
namespace enterdev\Composer;

class Patch
{
    public $targetPackage;
    public $source;
    public $sourcePackage;
    public $description;
    public $id;
    public $hash;

    public static function create($uri, $targetPackage, $description, $sourcePackage, $sourcePath)
    {
        $patch                = new Patch();
        $patch->targetPackage = $targetPackage;
        $patch->source        = $uri;
        $patch->sourcePackage = $sourcePackage;
        $patch->description   = $description;
        $patch->id            = md5($description . $targetPackage);

        $filename = realpath($sourcePath . DIRECTORY_SEPARATOR . $patch->source);
        if (!file_exists($filename))
            throw new \Exception('Cannot find patch file "' . $patch->source . '" in "' . $sourcePath . '"');

        $patch->hash = md5_file($filename);
        return $patch;
    }

    /**
     * @param Medic $medic
     * @param       $targetPath
     * @param       $sourcePath
     *
     * @throws \Exception
     */
    public function apply($medic, $targetPath, $sourcePath)
    {
        $filename = realpath($sourcePath . DIRECTORY_SEPARATOR . $this->source);
        if (!file_exists($filename))
            throw new \Exception('Cannot find patch file "' . $this->source . '" in "' . $sourcePath . '"');

        $patched = false;
        // The order here is intentional. p1 is most likely to apply with git apply.
        // p0 is next likely. p2 is extremely unlikely, but for some special cases,
        // it might be useful.
        $patchLevels = array('-p1', '-p0', '-p2');
        foreach ($patchLevels as $patchLevel)
        {
            $checked = $medic->executeCommand('cd %s && GIT_DIR=. git apply --check %s %s', $targetPath, $patchLevel,
                $filename);
            if ($checked)
            {
                // Apply the first successful style.
                $patched = $medic->executeCommand('cd %s && GIT_DIR=. git apply %s %s', $targetPath, $patchLevel,
                    $filename);
                break;
            }
        }

        // In some rare cases, git will fail to apply a patch, fallback to using
        // the 'patch' command.
        if (!$patched)
        {
            foreach ($patchLevels as $patchLevel)
            {
                // --no-backup-if-mismatch here is a hack that fixes some
                // differences between how patch works on windows and unix.
                if ($patched = $medic->executeCommand('patch %s --no-backup-if-mismatch -d %s < %s', $patchLevel,
                    $targetPath, $filename))
                {
                    break;
                }
            }
        }

        // If the patch *still* isn't applied, then give up and throw an Exception.
        // Otherwise, let the user know it worked.
        if (!$patched)
            throw new \Exception('Cannot apply patch ' . $filename);
    }
}