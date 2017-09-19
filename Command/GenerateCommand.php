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

use CampaignChain\CoreBundle\Entity\Bundle;
use CampaignChain\CoreBundle\Service\Elasticsearch;
use CampaignChain\CoreBundle\Wizard\Install\Driver\YamlConfig;
use CampaignChain\Security\Authentication\Server\OAuthBundle\Entity\Client;
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
        $io = new SymfonyStyle($input, $output);
        $io->title('Gathering .proto files from CampaignChain packages');
        $io->newLine();

        // Connect to Elasticsearch
        /** @var Elasticsearch $esService */
        $esService = $this->getContainer()->get('campaignchain.core.service.elasticsearch');
        $esClient = $esService->getClient();

        // Create a snapshot of Elasticsearch before we change anything
        if(
            $this->getContainer()->hasParameter('elasticsearch_s3_bucket') &&
            $this->getContainer()->hasParameter('elasticsearch_s3_region') &&
            $this->getContainer()->hasParameter('elasticsearch_s3_access_key') &&
            $this->getContainer()->hasParameter('elasticsearch_s3_secret_key')
        ){
            $esS3Bucket     = $this->getContainer()->getParameter('elasticsearch_s3_bucket');
            $esS3Region     = $this->getContainer()->getParameter('elasticsearch_s3_region');
            $esS3AccessKey  = $this->getContainer()->getParameter('elasticsearch_s3_access_key');
            $esS3SecretKey  = $this->getContainer()->getParameter('elasticsearch_s3_secret_key');

            if(strlen($esS3AccessKey) > 0 && strlen($esS3SecretKey) > 0){
                $params = [
                    'repository' => 'backup',
                    'body' => [
                        'type' => 's3',
                        'settings' => [
                            'bucket' => $esS3Bucket,
                            'region' => $esS3Region,
                            'access_key' => $esS3AccessKey,
                            'secret_key' => $esS3SecretKey,
                            'base_path' => 'esp/elasticsearch'

                        ]
                    ],
                ];
                $esClient->snapshot()->createRepository($params);
                $snapshot = time();
                $params = [
                    'repository' => 'backup',
                    'snapshot' => $snapshot,
                ];
                $esClient->snapshot()->create($params);
            }
        }

        $this->protoPath =
            str_replace('/', DIRECTORY_SEPARATOR,
                $this->getContainer()->getParameter('campaignchain_protobuf.bundle_proto_path')
            );

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

            $protoFiles = new Finder();
            $protoFiles->files()
                ->in($packageSchemaDir)
                ->name('*.proto');

            $files = [];
            $tableFiles = [];
            $tableMappings = [];

            /** @var SplFileInfo $protoFile */
            foreach ($protoFiles as $protoFile) {
                /*
                 * Register the .proto file to generate it later.
                 */
                $fs->copy($protoFile->getPathname(), $phpOutDir.DIRECTORY_SEPARATOR.$protoFile->getFilename(), true);
                $files[] = $protoFile->getPathname();
                $tableFiles[] = basename($protoFile->getPathname());

                /*
                 * Is there a configuration file for this .proto?
                 */
                $configFilePathname = str_replace('.proto', '.yml', $protoFile->getPathname());
                if(file_exists($configFilePathname)){
                    $configYml = new YamlConfig('', $configFilePathname);
                    $config = $configYml->read();

                    /*
                     * If Elasticsearch mapping is defined, then update the
                     * index accordingly.
                     */
                    if(isset($config['elasticsearch']) && isset($config['elasticsearch']['mappings'])) {
                        $esMappings = $config['elasticsearch']['mappings'];
                        $esType = str_replace('.proto', '', $protoFile->getFilename());

                        $esIndexAlias =
                            $this->getContainer()->getParameter('elasticsearch_index')
                            . '.esp.'
                            . str_replace('/', '.', $bundle->getName());
                        $esIndexNew = str_replace('campaignchain.esp.', 'campaignchain_.esp.', $esIndexAlias).'.'.time();
                        $aliases = array();
                        $esIndexOld = null;

                        $indexExists = $esClient->indices()->exists(array(
                            'index' => $esIndexAlias
                        ));

                        if(!$indexExists){
                            /*
                             * Index does not exist, so let's create it.
                             */

                            // Create a new index
                            $params = [
                                'index' => $esIndexNew,
                                'body' => [
                                    'mappings' => [
                                        $esType => [
                                            'properties' => [
                                                'properties' => $esMappings,
                                            ]
                                        ]
                                    ]
                                ],
                            ];
                            $io->writeln('Create new index ' . $esIndexNew);
                            $esClient->indices()->create($params);

                            // Create the alias
                            $params = [
                                'index' => $esIndexNew,
                                'name' => $esIndexAlias,
                            ];
                            $io->writeln('Creating alias from index ' . $esIndexNew . ' with name ' . $esIndexAlias);
                            $esClient->indices()->putAlias($params);

                            $tableMappings[] = 'Yes';
                        } else {
                            /*
                             * The index exists already, so let's make sure we
                             * gracefully update the field types.
                             */
                            try {
                                $aliases = $esClient->indices()->getAliases(array(
                                    'index' => $esIndexAlias,
                                ));

                                if (!isset($aliases[$esIndexAlias]['aliases'])) {
                                    $aliasesKeys = array_keys($aliases);
                                    $esIndexOld = $aliasesKeys[0];
                                }

                                if ($esIndexOld == null) {
                                    /*
                                     * Backwards compatibility:
                                     *
                                     * We don't use an index alias yet, let's
                                     * rename the index and create the alias.
                                     */

                                    // No alias yet
                                    $io->writeln('No alias yet for index ' . $esIndexAlias);

                                    // Create a new index
                                    $params = [
                                        'index' => $esIndexNew,
                                        'body' => [
                                            'mappings' => [
                                                $esType => [
                                                    'properties' => [
                                                        'properties' => $esMappings,
                                                    ]
                                                ]
                                            ]
                                        ],
                                    ];
                                    $io->writeln('Create new index ' . $esIndexNew);
                                    $esClient->indices()->create($params);

                                    // Copy the existing index
                                    $params = [
                                        'body' => [
                                            'source' => [
                                                'index' => $esIndexAlias,
                                            ],
                                            'dest' => [
                                                'index' => $esIndexNew,
                                            ],
                                        ],
                                    ];
                                    $io->writeln('Copying index ' . $esIndexAlias . ' to ' . $esIndexNew);
                                    $esClient->reindex($params);

                                    // Delete the existing index
                                    $io->writeln('Deleting index ' . $esIndexAlias);
                                    $esClient->indices()->delete(array(
                                        'index' => $esIndexAlias,
                                    ));

                                    // Create the alias
                                    $params = [
                                        'index' => $esIndexNew,
                                        'name' => $esIndexAlias,
                                    ];
                                    $io->writeln('Creating alias from index ' . $esIndexNew . ' with name ' . $esIndexAlias);
                                    $esClient->indices()->putAlias($params);

                                    $tableMappings[] = 'Yes';
                                } else {
                                    /*
                                     * An alias exists, so let's update the field
                                     * types.
                                     */
                                    try {
                                        /*
                                         * First, let's try to update index types.
                                         */
                                        $params = [
                                            'index' => $esIndexOld,
                                            'type' => $esType,
                                            'body' => [
                                                $esType => [
                                                    'properties' => [
                                                        'properties' => $esMappings,
                                                    ]
                                                ]
                                            ],
                                        ];

                                        $esClient->indices()->putMapping($params);

                                        $tableMappings[] = 'Yes';
                                    } catch (\Exception $e) {
                                        /*
                                         * There was a conflict changing a field type.
                                         * Hence, let's re-create the index without loosing
                                         * data with zero downtime as described here:
                                         * https://www.elastic.co/guide/en/elasticsearch/guide/current/index-aliases.html
                                         */

                                        // Create a new index
                                        $params = [
                                            'index' => $esIndexNew,
                                            'body' => [
                                                'mappings' => [
                                                    $esType => [
                                                        'properties' => [
                                                            'properties' => $esMappings,
                                                        ]
                                                    ]
                                                ]
                                            ],
                                        ];
                                        $io->writeln('Create new index ' . $esIndexNew);
                                        $esClient->indices()->create($params);

                                        // Move copied data back to re-created index
                                        $params = [
                                            'body' => [
                                                'source' => [
                                                    'index' => $esIndexOld,
                                                ],
                                                'dest' => [
                                                    'index' => $esIndexNew,
                                                ],
                                            ],
                                        ];
                                        $io->writeln('Copying index ' . $esIndexOld . ' to ' . $esIndexNew);
                                        $esClient->reindex($params);

                                        // Switch the alias
                                        $actions[] = array(
                                            'remove' => [
                                                'index' => $esIndexOld,
                                                'alias' => $esIndexAlias
                                            ]
                                        );
                                        $actions[] = array(
                                            'add' => [
                                                'index' => $esIndexNew,
                                                'alias' => $esIndexAlias
                                            ]
                                        );
                                        $params = [
                                            'body' => [
                                                'actions' => $actions,
                                            ]
                                        ];
                                        $io->writeln('Removing alias from ' . $esIndexOld . ' and adding it to ' . $esIndexNew);
                                        $esClient->indices()->updateAliases($params);

                                        // Delete the copy
                                        $io->writeln('Deleting old index ' . $esIndexOld);
                                        $esClient->indices()->delete(array(
                                            'index' => $esIndexOld,
                                        ));

                                        $tableMappings[] = 'Yes';
                                    }
                                }
                            } catch (\Exception $e) {
                                $io->error($e->getMessage());
                                $tableMappings[] = 'Error';
                            }
                        }
                    }
                } else {
                    $tableMappings[] = 'No';
                }
            }

            if(!count($files)){
                continue;
            }

            $protos[] = array(
                'bundle' => $bundle->getName(),
                'files' => $files,
            );
            $table[] = [$bundle->getName(), implode("\n", $tableFiles), implode("\n", $tableMappings)];

        }

        if(!is_array($table) || !count($table)) {
            $io->note('No .proto files found');
        } else {
            $io->section('protoc Output');
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

            $io->section('Summary');
            $io->table(['CampaignChain Module', 'Google Proto', 'Elasticsearch Mapping'], $table);
        }
    }
}