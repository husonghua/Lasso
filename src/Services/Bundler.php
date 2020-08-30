<?php

namespace Sammyjo20\Lasso\Services;

use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Sammyjo20\Lasso\Factories\BundleMetaFactory;
use Sammyjo20\Lasso\Factories\ZipFactory;
use Symfony\Component\Finder\Finder;

class Bundler
{
    /**
     * @var Compiler
     */
    protected $compiler;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var string
     */
    protected $bundle_id;

    /**
     * @var string
     */
    protected $environment;

    /**
     * Bundler constructor.
     */
    public function __construct()
    {
        $this->compiler = new Compiler();
        $this->filesystem = new Filesystem();
        $this->bundle_id = Str::random(20);
        $this->environment = config('lasso.storage.environment');

        $this->deleteLassoDirectory();
    }

    /**
     * @return void
     */
    private function deleteLassoDirectory(): void
    {
        $this->filesystem->deleteDirectory('.lasso');
    }

    /**
     * @param string $bundle_directory
     * @return string
     */
    private function createZipArchiveFromBundle(string $bundle_directory): string
    {
        $files = (new Finder())
            ->in(base_path('.lasso/bundle'))
            ->files();

        $relative_path = '.lasso/dist/' . $this->bundle_id . '.zip';

        $zip_path = base_path($relative_path);

        $zip = new ZipFactory($zip_path);

        foreach ($files as $file) {
            $zip->add($file->getPathname(), $file->getRelativePathname());
        }

        $zip->closeZip();

        return $zip_path;
    }

    /**
     * @param array $data
     */
    private function sendWebhooks(array $data)
    {
        $webhooks = config('lasso.webhooks.push', []);

        foreach($webhooks as $webhook) {
            Webhook::send($webhook, Webhook::PUSH_EVENT, $data);
        }
    }

    public function execute(bool $use_git = true)
    {
        $public_path = config('lasso.public_path');

        $this->compiler->buildAssets();

        // Command completed,
        $asset_url = config('app.asset_url', null);

        // Now let's move all the files into a temporary location.
        $this->filesystem->copyDirectory($public_path, '.lasso/bundle');

        $this->filesystem->ensureDirectoryExists('.lasso/dist');

        // Clean any excluded files/directories from the bundle
        (new BundleCleaner())->execute();

        // Todo: If the mode === CDN, we need to process the mix-manifest too.

        //        $manifest = array_map(function ($value) use ($asset_url) {
//            return $asset_url . '/' . $this->bundle_id . $value;
//        }, get_object_vars($manifest));
//
//        $this->filesystem->put('.lasso/bundle/mix-manifest.json', json_encode($manifest));

        $zip = $this->createZipArchiveFromBundle('.lasso/bundle');

        // Once the Zip is done, we can create the bundle-info file.
        $bundle_info = BundleMetaFactory::create($this->bundle_id, $zip);


        // If we are using Git, we will create a lasso-bundle.json file
        // locally inside the git repository, which will then be committed.

        if ($use_git === true) {
            $this->filesystem->replace(base_path('lasso-bundle.json'), $bundle_info);
        } else {
            $this->filesystem->put(base_path('.lasso/dist/bundle-meta.next.json'), $bundle_info);
            $this->uploadFile(base_path('.lasso/dist/bundle-meta.next.json'), 'bundle-meta.next.json');
        }

        // Create the bundle info as a file.
        $this->uploadFile($zip, $this->bundle_id . '.zip');

        // Delete the .lasso folder
        $this->deleteLassoDirectory();

        $push_to_git = config('lasso.storage.push_to_git', false);

        // If we're using git, commit the lasso-bundle file.
        if ($use_git === true && $push_to_git === true) {
            (new Committer())->commitAndPushBundle();
        }

        // Done. Send webhooks

        $this->sendWebhooks(
            (array)json_decode($bundle_info)
        );
    }

    /**
     * @param string $path
     * @param string $name
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function uploadFile(string $path, string $name)
    {
        $disk = config('lasso.storage.disk');
        $directory = config('lasso.storage.upload_to');

        $upload_path = $directory . '/' . $this->environment . '/' . $name;

        Storage::disk($disk)
            ->put($upload_path, $this->filesystem->get($path));
    }
}