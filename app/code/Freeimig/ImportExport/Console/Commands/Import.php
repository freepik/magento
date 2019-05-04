<?php
namespace Freeimig\ImportExport\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Backend\App\Area\FrontNameResolver;

class Import extends Command
{
    const INPUT_KEY_TYPE = 'type';
    const INPUT_KEY_LIMIT = 'limit';
    const INPUT_KEY_OVERRIDE = 'override';
    const TYPE_PRODUCT = 'product';
    const TYPE_CATEGORY = 'category';
    const LIMIT_DEFAULT = 1000;
    /**
     * @var ObjectManagerFactory
     */
    private $objectManagerFactory;

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * Constructor
     *
     * @param ObjectManagerFactory $objectManagerFactory
     */
    public function __construct(
        ObjectManagerFactory $objectManagerFactory,
        \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
        $this->objectManagerFactory = $objectManagerFactory;
        $this->resourceConnection = $resourceConnection;
        $this->laravel_connection = $this->resourceConnection->getConnection('laravel_resource');
        $this->categoryFactory = $this->getObjectManager()->get('Magento\Catalog\Model\CategoryFactory');
        $this->productFactory = $this->getObjectManager()->get('Magento\Catalog\Model\ProductFactory');
        parent::__construct();
    }

    protected function configure()
    {
        $arguments = [
            new InputArgument(
                self::INPUT_KEY_TYPE,
                InputArgument::REQUIRED,
                'product or category'
            ),
        ];
        $options = [
            new InputOption(
                self::INPUT_KEY_LIMIT,
                null,
                InputOption::VALUE_NONE,
                'Limit number products'
            ),
            new InputOption(
                self::INPUT_KEY_OVERRIDE,
                null,
                InputOption::VALUE_NONE,
                'Override exists product'
            ),
        ];
        $this->setName('freeimig:import')
            ->setDescription('Import product/category from laravel db')
            ->setDefinition(array_merge($arguments/* , $options */));
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $type = $input->getArgument(self::INPUT_KEY_TYPE);

        switch ($type) {
            case self::TYPE_PRODUCT:
                return $this->importProduct();
                break;
            case self::TYPE_CATEGORY:
                
                return $this->importCategoryFromFilesTable();
                return $this->importCategory();
                break;
            
            default:
                # code...
                break;
        }

        return;

        $configWriter = $this->getObjectManager()
                ->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);

    }

    protected function importCategoryFromFilesTable($offset=0) {
        
        // $connection = $this->resourceConnection->getConnection('laravel_resource');
        $select = $this->laravel_connection->select()
        ->from('freepik_files')->limit(self::LIMIT_DEFAULT, $offset)->order('id ASC');
        
        $files = $this->laravel_connection->fetchAll($select);

        $total = count($files);
        $this->output->writeln("<info>Start from offset {$offset}: Found {$total} files</info>");
        
        foreach ($files as $i => $file) {
            $offset = $offset + $i;
            $this->output->writeln("<info>File offset #{$offset}");
            $categories = json_decode($file['categoryList'], true);
            foreach ($categories as $array) {
                list('n' => $title, 's' => $slug) = $array;
                $category = $this->categoryFactory->create()->loadByAttribute('url_key', $slug);
                if($category) {
                    $this->output->writeln("<error>Category {$title} exists!</error>");
                } else {
                    $category = $this->categoryFactory->create();

                    $category->setName($title);
                    $category->setIsActive(true);
                    $category->setUrlKey($slug);
                    $category->setData('include_in_menu', false);
                    $category->setParentId(2);
                    // $category->setStoreId(1);
                    $category->setPath('1/2');
                    $category->save();
                    $this->output->writeln("<info>Category {$title} save success!</info>");
                }

                continue;
            }
            // save product
            $product = $this->productFactory->create()->loadByAttribute('url_key', $slug);
            if($product) {
                $this->output->writeln("<error>Product {$file['title']} exists!</error>");
            } else {
                $product = $this->productFactory->create();
                $product->setName('Test Product');
                $product->setTypeId('virtual');
                $product->setAttributeSetId(4);
                $product->setSku($file['id']);
                $product->setWebsiteIds(array(1));
                $product->setVisibility(4); // Catalog, Search
                $product->setPrice(array(1));
                // $product->setImage('/testimg/test.jpg');
                // $product->setSmallImage('/testimg/test.jpg');
                // $product->setThumbnail('/testimg/test.jpg');
                $product->setStockData(array(
                    'use_config_manage_stock' => 0, //'Use config settings' checkbox
                    'manage_stock' => 0, //manage stock
                    //'min_sale_qty' => 1, //Minimum Qty Allowed in Shopping Cart
                    //'max_sale_qty' => 2, //Maximum Qty Allowed in Shopping Cart
                    'is_in_stock' => 1, //Stock Availability
                    // 'qty' => 100 //qty
                ));
                $product->setData([
                    'thumbnail_large_url' => '',
                    'thumbnail_small_url' => '',
                ]);
                $product->save();
                $this->output->writeln("<info>Product {$file['title']} save success!</info>");
            }

            continue;
        }

        if($total) {
            // $offset = $offset + $total;
            return $this->importCategoryFromFilesTable($offset);
        } else {
            $this->output->writeln("Empty");
        }
    }

    protected function importCategory() {
        $connection = $this->resourceConnection->getConnection('laravel_resource');
        $select = $connection->select()
            ->from(
                ['freepik_categories' => 'freepik_categories']
            );
        $categories = $connection->fetchAll($select);

        print_r($categories);
    }

    /**
     * Gets initialized object manager
     *
     * @return ObjectManagerInterface
     */
    protected function getObjectManager() {
        if (null == $this->objectManager) {
            $area = FrontNameResolver::AREA_CODE;
            $this->objectManager = $this->objectManagerFactory->create($_SERVER);
            /** @var \Magento\Framework\App\State $appState */
            $appState = $this->objectManager->get(\Magento\Framework\App\State::class);
            $appState->setAreaCode($area);
            $configLoader = $this->objectManager->get(\Magento\Framework\ObjectManager\ConfigLoaderInterface::class);
            $this->objectManager->configure($configLoader->load($area));
        }
        return $this->objectManager;
    }
}