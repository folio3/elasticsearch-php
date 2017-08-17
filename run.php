<?php
/**
 * Created by PhpStorm.
 * User: hunain
 * Date: 16/08/17
 * Time: 1:48 PM
 */


use Elasticsearch\ClientBuilder;

declare(strict_types = 1);

error_reporting(E_ALL | E_STRICT);

// Set the default timezone. While this doesn't cause any tests to fail, PHP
// complains if it is not set in 'date.timezone' of php.ini.
date_default_timezone_set('UTC');
// Ensure that composer has installed all dependencies
if (!file_exists(__DIR__ . '/composer.lock')) {
    die("Dependencies must be installed using composer:\n\nphp composer.phar install --dev\n\n"
        . "See http://getcomposer.org for help with installing composer\n");
}

// Include the composer autoloader
$autoloader = require_once __DIR__ . '/vendor/autoload.php';


/**
 * @param $name
 * @param $index
 * @param $synonyms
 * @syntax createSynonymFilter('my_synonym_filter','my_index',['bag,bagpack','sleaveless,tank']);
 */
function createSynonymFilter($name,$index,$synonyms){
    $client = ClientBuilder::create()->build();
    $client->indices()->createSynonymFilter($name,$index,$synonyms);
}

/**
 * @param $name
 * @param $index
 * @param $filters
 * @param $tokenizer
 * @syntax createAnalyzer('my_analyzer','my_index',['my_synonym_filter'],'standard');
 */
function createAnalyzer($name,$index,$filters,$tokenizer){
    $client = ClientBuilder::create()->build();
    $client->indices()->createAnalyzer($name,$index,$filters,$tokenizer);
}

/**
 *
 */
function import_item_data()
{
    $query = "SELECT
  cpe.entity_id,
  cpe.sku,
  cpev.value AS name,
  cpet.value AS description
FROM
  catalog_product_entity cpe
  JOIN catalog_product_entity_varchar cpev
    ON cpe.entity_id = cpev.entity_id
    AND cpev.attribute_id = (
      SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'name' AND entity_type_id = 4
    )
  JOIN catalog_product_entity_text cpet
    ON cpe.entity_id = cpet.entity_id
    AND cpet.attribute_id = (
      SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'description' AND entity_type_id = 4
    )
  JOIN catalog_product_entity_int cpei
    ON cpe.entity_id = cpei.entity_id
    AND cpei.attribute_id = (
      SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'visibility' AND entity_type_id = 4
    )
  JOIN url_rewrite ur
    ON cpe.entity_id = ur.entity_id
    AND ur.entity_type = 'product'
    AND ur.metadata IS NULL
WHERE
  cpei.value != 1;";

    $client = ClientBuilder::create()->build();
    $conn = mysqli_connect('localhost', 'root', '', 'magento2');

// Check connection
    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }
    $result = mysqli_query($conn, $query);
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data [] = $row;
    }
    $client->import('catalog', 'data', $data);
}

/**
 *
 */
function import_order_data()
{
    $client = ClientBuilder::create()->build();
    $query = 'SELECT item_id,sku,customer_id,order_id FROM magento2.sales_order join sales_order_item on sales_order.entity_id = sales_order_item.order_id;';

    $conn = mysqli_connect('localhost', 'root', '', 'magento2');

// Check connection
    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }
    $result = mysqli_query($conn, $query);
    $data = [];
    $order_id = 0;
    $current_index = -1;
    while ($row = mysqli_fetch_assoc($result)) {
        if ($order_id != $row['order_id']) {
            $order_id = $row['order_id'];
            $data [] = ['id' => $order_id, 'customer_id' => $row['customer_id'], 'items' => [['id' => $row['item_id'], 'sku' => $row['sku']]]];
            $current_index++;
        } else {
            $data[$current_index]['items'] [] = ['id' => $row['item_id'], 'sku' => $row['sku']];
        }
    }
    print_r($data);
    $client->import('order3', 'data', $data);
}

function generate_similar_product_recommendation($item_id,$index,$type){
    $client = ClientBuilder::create()->build();
    $params = [
        'index' => $index,
        'type' => $type,
        'body' => [
            'query' => [
                'term' => [
                    'items.id' => $item_id
                ]
            ],
            'aggs'=>[
                'bestMatch'=> [
                    'terms'=> [
                        'field'=>'items.id',
                        'exclude'=>[$item_id]
                    ]
                ]
            ]
        ]
    ];
    print_r($client->search($params)['aggregations']['bestMatch']['buckets']);
}

generate_similar_product_recommendation(3,'order3','data');

