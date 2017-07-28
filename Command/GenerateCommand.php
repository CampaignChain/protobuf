<?php
/*
 * Copyright 2016 CampaignChain, Inc. <info@campaignchain.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace CampaignChain\ProtobufBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class GenerateCommand extends ContainerAwareCommand
{
    private $migrationPath;

    protected function configure()
    {
        $this
            ->setName('campaignchain:protobuf:generate')
            ->setDescription('Produces PHP classes from .proto files for all modules')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->protoPath =
            str_replace('/', DIRECTORY_SEPARATOR,
                $this->getContainer()->getParameter('campaignchain_protobuf.bundle_proto_path')
            );

        $io = new SymfonyStyle($input, $output);
        $io->title('Gathering .proto files from CampaignChain packages');
        $io->newLine();

        $locator = $this->getContainer()->get('campaignchain.core.module.locator');
        $bundleList = $locator->getAvailableBundles();

        if (empty($bundleList)) {
            $io->error('No CampaignChain Module found');
            return;
        }

        $phpOutDir  = $this->getContainer()->getParameter('campaignchain_protobuf.php_out');

        $fs = new Filesystem();

        $table = [];

        /** @var Bundle $bundle */
        foreach ($bundleList as $bundle) {
            $packageSchemaDir = $this->getContainer()->getParameter('kernel.root_dir').
                DIRECTORY_SEPARATOR.'..'.
                DIRECTORY_SEPARATOR.$bundle->getPath().$this->protoPath;

            if (!$fs->exists($packageSchemaDir)) {
                continue;
            }

            $migrationFiles = new Finder();
            $migrationFiles->files()
                ->in($packageSchemaDir)
                ->name('*.proto');

            $files = [];
            $tableFiles = [];

            /** @var SplFileInfo $migrationFile */
            foreach ($migrationFiles as $migrationFile) {
                $fs->copy($migrationFile->getPathname(), $phpOutDir.DIRECTORY_SEPARATOR.$migrationFile->getFilename(), true);
                $files[] = $migrationFile->getPathname();
                $tableFiles[] = basename($migrationFile->getPathname());

            }

            if(!count($files)){
                continue;
            }

            $protos[] = array(
                'bundle' => $bundle->getName(),
                'files' => $files,
            );
            $table[] = [$bundle->getName(), implode(', ', $tableFiles)];

        }
        if(!is_array($table) || !count($table)) {
            $io->note('No .proto files found');
        } else {
            $io->table(['Module', 'Proto'], $table);

            foreach($protos as $proto) {
                // Create php_out directory if it doesn't exist yet.
                $modulePhpOutdir = $phpOutDir . DIRECTORY_SEPARATOR . $proto['bundle'];
                $fs->mkdir($modulePhpOutdir);

                foreach ($proto['files'] as $protoFile) {
                    $command = 'protoc --proto_path=' . dirname($protoFile) . ' --php_out=' . $modulePhpOutdir . ' ' . $protoFile;
                    $process = new Process($command);
                    $process->run();

                    // executes after the command finishes
                    if (!$process->isSuccessful()) {
                        throw new ProcessFailedException($process);
                    }

                    $io->success($command);

                    $io->write($process->getOutput());
                }
            }
        }
    }
}