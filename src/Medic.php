<?php
namespace enterdev\Composer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\EventDispatcher\EventDispatcher;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Installer\PackageEvents;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\ScriptEvents;
use Composer\Installer\PackageEvent;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\Process;

class Medic implements PluginInterface, EventSubscriberInterface
{
    /** @var Composer $composer */
    protected $composer;
    /** @var IOInterface $io */
    protected $io;
    /** @var EventDispatcher $eventDispatcher */
    protected $eventDispatcher;
    /** @var ProcessExecutor $executor */
    protected $executor;
    /** @var Patch[][] $patches */
    protected $patches;
    protected $rootPath;
    protected $installedPatches;

    /**
     * Returns an array of event names this plugin wants to listen to.
     */
    public static function getSubscribedEvents()
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'gatherPatches',
            PackageEvents::POST_PACKAGE_UPDATE  => 'gatherPatches',
            ScriptEvents::POST_INSTALL_CMD      => 'installPatches',
            ScriptEvents::POST_UPDATE_CMD       => 'installPatches',
        ];
    }

    /**
     * Apply plugin modifications to composer
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer         = $composer;
        $this->io               = $io;
        $this->eventDispatcher  = $composer->getEventDispatcher();
        $this->executor         = new ProcessExecutor($this->io);
        $this->patches          = [];
        $this->rootPath         = getcwd();
        $this->installedPatches = [];
        $this->setup();
    }

    /**
     * Get installed patches
     */
    public function setup()
    {
        /** @var RepositoryManager $repositoryManager */
        $repositoryManager = $this->composer->getRepositoryManager();
        /** @var RepositoryInterface $localRepository */
        $localRepository = $repositoryManager->getLocalRepository();
        /** @var Package[] $packages */
        $packages = $localRepository->getPackages();
        $this->gatherPatchesFromPackage($this->composer->getPackage());
        $this->gatherInstalledPatches();
        foreach ($packages as $package)
        {
            if (!($package instanceof AliasPackage))
                $this->gatherPatchesFromPackage($package);
        }
    }

    public function gatherPatches(PackageEvent $event)
    {
        $operation = $event->getOperation();
        /** @var InstallationManager $manager */
        $package = $this->getPackageFromOperation($operation);

        if ($this->io->isVerbose() && isset($this->installedPatches[$package->getName()]))
            $this->io->write(' - Resetting installed patches for ' . $package->getName());

        unset($this->installedPatches[$package->getName()]);
        $this->gatherPatchesFromPackage($package);
    }

    /**
     * @param PackageInterface $package
     */
    public function gatherPatchesFromPackage($package)
    {
        $patches = 0;
        foreach ($this->getPatchesFromPackage($package) as $patch)
        {
            if (!isset($this->patches[$patch->targetPackage]))
                $this->patches[$patch->targetPackage] = [];
            if (!isset($this->patches[$patch->targetPackage][$patch->id]))
            {
                $this->patches[$patch->targetPackage][$patch->id] = $patch;
                $patches++;
                continue;
            }
            if ($this->patches[$patch->targetPackage][$patch->id]->hash > $patch->hash)
                continue;
            $this->patches[$patch->targetPackage][$patch->id] = $patch;
            $patches++;
        }

        if ($patches && $this->io->isVerbose())
            $this->io->write('Found ' . $patches . ' patches in package ' . $package->getName());
    }

    public function installPatches()
    {
        /** @var RepositoryInterface $localRepository */
        $localRepository = $this->composer->getRepositoryManager()->getLocalRepository();
        /** @var InstallationManager $installationManager */
        $installationManager = $this->composer->getInstallationManager();

        foreach ($this->patches as $targetPackage => $patches)
        {
            if (!isset($this->installedPatches[$targetPackage]))
                $this->installedPatches[$targetPackage] = [];

            foreach ($patches as $patch)
            {
                //If at least one of the found patch's hashes is different then the installed one: reinstall the whole package
                if (isset($this->installedPatches[$patch->targetPackage][$patch->id])
                    && $this->installedPatches[$patch->targetPackage][$patch->id] != $patch->hash
                )
                {
                    /** @var Package $targetPackage */
                    $targetPackage      = $localRepository->findPackage($patch->targetPackage, '*');
                    $uninstallOperation = new UninstallOperation($targetPackage,
                        'Removing package so it can be re-installed and re-patched.');
                    $this->io->write('<info>Removing package ' . $targetPackage->getName() .
                        ' so that it can be re-installed and re-patched.</info>');
                    $installationManager->uninstall($localRepository, $uninstallOperation);
                    $installOperation = new InstallOperation($targetPackage,
                        'Installing package that was removed so it can be re-patched.');
                    $installationManager->install($localRepository, $installOperation);
                    //Clear the installed patches array for this package
                    $this->installedPatches[$patch->targetPackage] = [];
                    break;
                }
            }

            foreach ($patches as $patch)
            {
                //If we got a patch with the same installed hash - ignore it
                if (isset($this->installedPatches[$patch->targetPackage][$patch->id])
                    && $this->installedPatches[$patch->targetPackage][$patch->id] == $patch->hash
                )
                {
                    continue;
                }

                $this->io->write('');
                $this->io->write(' - Applying patch <info>' . $patch->description . '</info> for <info>' .
                    $patch->targetPackage . '</info>');
                /** @var Package $targetPackage */
                $targetPackage = $localRepository->findPackage($patch->targetPackage, '*');
                $targetPath    = $installationManager->getInstallPath($targetPackage);

                /** @var Package $sourcePackage */
                $sourcePackage = $localRepository->findPackage($patch->sourcePackage, '*');
                if (!$sourcePackage)
                    $sourcePath = $this->rootPath;
                else
                    $sourcePath =
                        $installationManager->getInstaller($sourcePackage->getType())->getInstallPath($sourcePackage);

                $patch->apply($this, $targetPath, $sourcePath);

                $this->installedPatches[$patch->targetPackage][$patch->id] = $patch->hash;
                $this->saveInstalledPatches();
            }
        }
        $this->io->write('');
    }

    /**
     * Get a Package object from an OperationInterface object.
     *
     * @param OperationInterface $operation
     *
     * @return PackageInterface
     * @throws \Exception
     */
    protected function getPackageFromOperation(OperationInterface $operation)
    {
        if ($operation instanceof InstallOperation)
            $package = $operation->getPackage();
        elseif ($operation instanceof UpdateOperation)
            $package = $operation->getTargetPackage();
        else
            throw new \Exception('Unknown operation: ' . get_class($operation));

        return $package;
    }

    /**
     * @param PackageInterface $package
     *
     * @return Patch[]
     */
    private function getPatchesFromPackage($package)
    {
        $gatheredPatches = [];
        $extra           = $package->getExtra();
        if (!isset($extra['medic']))
            return [];
        $medic = $extra['medic'];

        /** @var InstallationManager $manager */
        $manager = $this->composer->getInstallationManager();

        foreach ($medic as $targetPackage => $patches)
            foreach ($patches as $uri => $description)
            {
                if ($package == $this->composer->getPackage())
                    $sourcePath = $this->rootPath;
                else
                    $sourcePath = $manager->getInstallPath($package);
                $gatheredPatches[] = Patch::create(
                    $uri, $targetPackage, $description, $package->getName(), $sourcePath);
            }

        return $gatheredPatches;
    }

    /**
     * Executes a shell command with escaping.
     *
     * @param string $cmd
     *
     * @return bool
     */
    public function executeCommand($cmd)
    {
        // Shell-escape all arguments except the command.
        $args = func_get_args();
        foreach ($args as $index => $arg)
        {
            if ($index !== 0)
                $args[$index] = escapeshellarg($arg);
        }

        // And replace the arguments.
        $command = call_user_func_array('sprintf', $args);
        $output  = '';

        if ($this->io->isDebug())
        {
            $this->io->write('<comment>' . $command . '</comment>');
            $io     = $this->io;
            $output = function ($type, $data) use ($io)
            {
                if ($type == Process::ERR)
                    $io->write('<error>' . $data . '</error>');
                else
                    $io->write('<comment>' . $data . '</comment>');
            };
        }

        return ($this->executor->execute($command, $output) == 0);
    }

    private function gatherInstalledPatches()
    {
        $this->installedPatches = [];
        $installedPatchesFile   = $this->rootPath . DIRECTORY_SEPARATOR . 'medic.lock';

        if (file_exists($installedPatchesFile))
            $this->installedPatches = json_decode(file_get_contents($installedPatchesFile), true);

        if ($this->io->isVerbose())
            $this->io->write('Found ' . count($this->installedPatches) . ' reportedly installed patches');
    }

    private function saveInstalledPatches()
    {
        $installedPatchesFile = $this->rootPath . DIRECTORY_SEPARATOR . 'medic.lock';
        file_put_contents($installedPatchesFile, json_encode($this->installedPatches));
    }
}