<?php

class EasySize_SizeFilter_IndexController extends Mage_Core_Controller_Front_Action {

    public function indexAction() {
        $categories = Mage::getModel('catalog/category')
            ->getCollection()
            ->addAttributeToSelect('*')
            ->addIsActiveFilter();

        $manufacturer = $this->getAttributeIdByName('manufacturer', 'Penguin');
        $size = $this->getAttributeIdByName('size', 'Small');

        $collection = Mage::getModel('catalog/product')->getCollection()
            ->addAttributeToSelect('*')
            ->addAttributeToFilter('type_id', array('eq' => 'configurable'))
            ->addAttributeToFilter('manufacturer', $manufacturer)
            ->setPageSize(20)
            ->setCurPage(1)
            ->load()
            ;

        echo $collection->getSelectSql(true)."<br/>";

        foreach($collection as $product) {
            echo $product->getID().": ".$product->getName().": ".$product->getAttributeText('manufacturer')."<br/>";
        }
    }

    public function cacheAction() {
        $start_time = time();
        $_productCollection = Mage::getModel('catalog/product')
            ->getCollection()
            ->addAttributeToSelect('manufacturer')
            ->addAttributeToSelect('gender')
            ->addAttributeToFilter('type_id', array('eq' => 'configurable'))
            ->setPageSize(100)
            ->setCurPage(1)
            ->load()
            ;

        foreach ($_productCollection as $product){
            $product_brand = strtolower($product->getAttributeText('manufacturer'));
            $product_gender = strtolower($product->getAttributeText('gender'));
            try {
                $childProducts = Mage::getModel('catalog/product_type_configurable')
                    ->getUsedProducts(null, $product);

                foreach($childProducts as $_cprod) {
                    $product_size = $_cprod->getAttributeText('size');
                    if($product_size) {
                        $item = new stdClass();
                        $item->size = $product_size;
                        $item->gender = $product_gender;
                        $item->brand = $product_brand;
                        $item->id = $product->getID();
                        $item->categories = [];

                        $categories = $product->getCategoryCollection()
                            ->addAttributeToSelect('name');
                        foreach($categories as $category) {
                            $item->categories[] = $category->getName();
                        }

                        $cache[] = $item;
                    }
                }
            } catch (Exception $e) {
                echo 'Product id: ' . $product->getID();
                var_dump($e);
            }
        }

        $cacheId = 'es-sizefilter-products';

        // Cache items
        Mage::app()->getCache()->save(serialize($cache), $cacheId);

        $end_time= time();
        $completed_in = $end_time - $start_time;
        echo "Completed in {$completed_in} seconds\n\n";
        var_dump($cache);
    }

    public function cachedAction() {
        $start_time = time();

        $cacheId = 'es-sizefilter-products';
        if (false !== ($data = Mage::app()->getCache()->load($cacheId))) {
            $data = unserialize($data);
        }

        $end_time= time();
        $completed_in = $end_time - $start_time;
        echo "Completed in {$completed_in} seconds\n\n";
        
        var_dump($data);
    }

    private function getAttributeIdByName($attribute_name, $attribute_value) {
        $attr = Mage::getModel('catalog/product')->getResource()->getAttribute($attribute_name);

        if($attr->usesSource()) {
            $attribute_id = $attr->getSource()->getOptionId($attribute_value);
            return $attribute_id;
        }
        return -1;
    }

    public function resultAction() {
        echo 'result action';
//        $this->loadLayout();
//        $this->renderLayout();
    }

    public function createAction()
    {
        $MANUFACTURERS = $this->getManufacturers();
        $SIZES = array("6" => "L", "5" => "M", "4" => "S", "3" => "XS");
        $PRODUCTS_TO_CREATE = 20;

        for ($i=0; $i < $PRODUCTS_TO_CREATE; $i++) {
            $product = new stdClass();
            $manufacturer = $MANUFACTURERS[rand(0, (sizeof($MANUFACTURERS) - 1))];
            Mage::log('Procesing file '.($i+1)." out of {$PRODUCTS_TO_CREATE}. Brand name: ".$manufacturer['name'], null, 'productGenerator.log');
            $product->brand_name = $manufacturer['name'];
            $product->manufacturer_id = $manufacturer['id'];
            $product->image_url = $manufacturer->url;
            $product->name = $product->brand_name . ' Test T-Shirt ' . rand(0, 100000);
            $product->description = 'Lorepsum ipsum blah test t-shirt';
            $product->gender_id = 10;

            $configProductDataIndex = 0;
            $configProduct = $this->createConfigurableProduct($product);
            // Add image to configurable product
            $image_type = substr(strrchr($manufacturer['url'], "."), 1); //find the image extension
            $filename = md5($manufacturer['url'] . $product->name) . '.' . $image_type; //give a new name, you can modify as per your requirement
            $filepath = Mage::getBaseDir('media') . DS . 'import' . DS . $filename; //path for temp storage folder: ./media/import/
            file_put_contents($filepath, file_get_contents(trim($manufacturer['url'])));
            $mediaAttribute = array('image', 'small_image', 'thumbnail');
            $configProduct->addImageToMediaGallery($filepath, $mediaAttribute, true, false);

            // Start linking products
            $configProduct->getTypeInstance()->setUsedProductAttributeIds(array(133)); //attribute ID of attribute 'size' in my store
            $configurableAttributesData = $configProduct->getTypeInstance()->getConfigurableAttributesAsArray();

            $configProduct->setCanSaveConfigurableAttributes(true);
            $configProduct->setConfigurableAttributesData($configurableAttributesData);
            $configurableProductsData = array();

            foreach ($SIZES as $id => $size) {
                $product->size_name = $size;
                $product->size_id = $id;
                $simpleProduct = $this->createSimpleProduct($product); // create simple product to link

                $configurableProductsData[$simpleProduct->getId()] = array( //['920'] = id of a simple product associated with this configurable
                    $configProductDataIndex => array(
                        'label' => $product->size_name, //attribute label
                        'attribute_id' => '133', //attribute ID of attribute 'color' in my store
                        'value_index' => $product->size_id, //value of 'Green' index of the attribute 'color'
                        'is_percent' => '0', //fixed/percent price for this option
                        'pricing_value' => '21' //value for the pricing
                    )
                );
                $configProductDataIndex++;
            }

            $configProduct->setConfigurableProductsData($configurableProductsData);
            $configProduct->save();
        }
    }

    private function createSimpleProduct($product) {
        /*
         * Product is an object containing
         *  brand_name
         *  brand_id // get from 'sizefilter/index/test?attr=manufacturer'
         *  size_name
         *  size_id // get from 'sizefilter/index/test?attr=size'
         *
         *  description // some random text
         */
        $ATTR_SET_ID = 10;

        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);
        $simpleProduct = Mage::getModel('catalog/product');
        try {
            $simpleProduct
                ->setWebsiteIds(array(1))
                ->setAttributeSetId($ATTR_SET_ID)
                ->setTypeId('simple')
                ->setCreatedAt(strtotime('now'))
                ->setSku($product->name.' '.$product->size_name)
                ->setName($product->name.' '.$product->size_name)
                ->setWeight(10.0000)
                ->setStatus(1)
                ->setTaxClassId(1)
                ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE)
                ->setSize($product->size_id)
                ->setNewsFromDate('06/26/2014')
                ->setNewsToDate('06/30/2014')
                ->setCountryOfManufacture('UK')
                ->setPrice(rand(20, 100))
                ->setCost(rand(100, 120))
                ->setMetaTitle($product->description)
                ->setMetaKeyword($product->description)
                ->setMetaDescription($product->description)
                ->setDescription($product->description)
                ->setShortDescription($product->description)
                ->setStockData(array(
                        'use_config_manage_stock' => 0, //'Use config settings' checkbox
                        'manage_stock' => 1, //manage stock
                        'min_sale_qty' => 1, //Minimum Qty Allowed in Shopping Cart
                        'max_sale_qty' => 2, //Maximum Qty Allowed in Shopping Cart
                        'is_in_stock' => 1, //Stock Availability
                        'qty' => rand(0, 10) //qty
                    )
                )
                ->setCategoryIds(array(2, 12)) // 2 - root default, 4 - t-shirts motive, 12 t-shirts
                ->save();

            return $simpleProduct;
        } catch (Exception $e) {
            Mage::log($e->getMessage());
            echo "<br/><br/><br/>Simple product<br/>";
            var_dump($product);
            echo "<br/>Error<br/>";
            echo $e->getMessage();
        }
    }

    private function getManufacturers() {
//        {"21":"Emporio Armani","15":"G-Star Raw","18":"Lyle & Scott","17":"Penguin","19":"Polo Ralph Lauren","22":"Pretty Green","7":"Solid","20":"Stone Island","16":"True Religion"}
        $MANUFACTURERS = array();
        $MANUFACTURERS[] = array(
            'id'=>7,
            'name'=>'Solid',
            'url'=>'http://g03.a.alicdn.com/kf/HTB19.oqHVXXXXX7aXXXq6xXFXXXb/Wholesale-Euro-Size-Solid-T-Shirts-Men-Cotton-Blank-Man-T-Shirt-O-Neck-Short-Sleeve.jpg'
        );

        $MANUFACTURERS[] = array(
            'id'=>15,
            'name'=>'G-Star Raw',
            'url'=>'https://cdn.media.g-star.com/image/635308408466413253BD_P18.jpg'
        );

        $MANUFACTURERS[] = array(
            'id'=>16,
            'name'=>'True Religion',
            'url'=>'http://images.asos-media.com/inv/media/1/7/7/9/5369771/image3xxl.jpg'
        );

        $MANUFACTURERS[] = array(
            'id'=>17,
            'name'=>'Penguin',
            'url'=>'https://cdnd.lystit.com/photos/99c8-2014/02/15/original-penguin-beige-distressed-circle-logo-penguin-t-shirt-product-1-17678062-0-669459717-normal.jpeg'
        );

        $MANUFACTURERS[] = array(
            'id'=>18,
            'name'=>'Lyle & Scott',
            'url'=>'http://www.infinities.co.uk/images/lyle-and-scott-basic-t-shirt-yellow-p94240-31339_zoom.jpg'
        );

        $MANUFACTURERS[] = array(
            'id'=>19,
            'name'=>'Polo Ralph Lauren',
            'url'=>'http://www.chameleonmenswear.co.uk/images/products/zoom/1349170809-97322000.jpg'
        );

        $MANUFACTURERS[] = array(
            'id'=>20,
            'name'=>'Stone Island',
            'url'=>'http://www.designerwear2u.co.uk/images/products/zoom/1359046975-77525000.jpg'
        );

        $MANUFACTURERS[] = array(
            'id'=>21,
            'name'=>'Emporio Armani',
            'url'=>'http://i.ebayimg.com/images/i/321396858541-0-1/s-l1000.jpg'
        );

        $MANUFACTURERS[] = array(
            'id'=>22,
            'name'=>'Pretty Green',
            'url'=>'http://images.jonbarrie.co.uk/images/products/zoom/1366112586-79725900.jpg'
        );

        return $MANUFACTURERS;
    }

    private function createConfigurableProduct($product) {
        $configProduct = Mage::getModel('catalog/product');
        $ATTR_SET_ID = 10;
        try {
            $configProduct
                ->setWebsiteIds(array(1))
                ->setAttributeSetId($ATTR_SET_ID)
                ->setTypeId('configurable')
                ->setCreatedAt(strtotime('now'))
                ->setSku($product->name) //SKU
                ->setName($product->name) //product name
                ->setWeight(10.0000)
                ->setStatus(1)
                ->setTaxClassId(1)
                ->setManufacturer($product->manufacturer_id)
                ->setGender($product->gender_id)
                ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)
                ->setNewsFromDate('06/26/2014')
                ->setNewsToDate('06/30/2014')
                ->setCountryOfManufacture('UK')
                ->setPrice(rand(20, 100))
                ->setCost(rand(100, 120))
                ->setMetaTitle($product->description)
                ->setMetaKeyword($product->description)
                ->setMetaDescription($product->description)
                ->setDescription($product->description)
                ->setShortDescription($product->description)
                ->setMediaGallery(array('images'=>array (), 'values'=>array ()))
                ->setStockData(array(
                        'use_config_manage_stock' => 0, //'Use config settings' checkbox
                        'manage_stock' => 1, //manage stock
                        'is_in_stock' => 1, //Stock Availability
                    )
                )
                ->setCategoryIds(array(2, 12)) // 2 - root default, 4 - t-shirts, // 12 - tshirts
                ;

            return $configProduct;
        } catch (Exception $e) {
            Mage::log($e->getMessage());
            echo "<br/><br/><br/>Configurable product<br/>";
            var_dump($configProduct);
            echo "<br/>Error<br/>";
            echo $e->getMessage();
        }
    }

    public function testAction() {
        $p = Mage::getModel('catalog/product')->load(115);
        var_dump($p);

    }

    public function attributeAction() {
        /*
         * URL: sizefilter/index/test?attr=size
         * PARAMS
         *  attr - attribute name to get results from
         * RETURNS
         *  array of ids as keys and option names as values from the asked attribute name
         */
        if(isset($_GET['attr'])) {
            echo json_encode($this->getAttributeNamesWithIdByName($_GET['attr']));
        } else {
            echo 'Remember to set "attr" param to the attribute name you want to get data from';
        }

    }

    private function getAttributeNamesWithIdByName($name) {
        $attribute = Mage::getModel('eav/entity_attribute')
            ->loadByCode('catalog_product', $name);

        $valuesCollection = Mage::getResourceModel('eav/entity_attribute_option_collection')
            ->setAttributeFilter($attribute->getData('attribute_id'))
            ->setStoreFilter(0, false);

        $preparedAttributeNames = array();
        $result = array();
        foreach($valuesCollection as $value) {
            $preparedAttributeNames[$value->getOptionId()] = $value->getValue();
            $result[$value->getOptionId()] = $value->getValue();
        }

        return $result;
    }
}