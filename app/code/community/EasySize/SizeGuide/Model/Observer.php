<?php

class EasySize_SizeGuide_Model_Observer {
    public function sendTrackingData($observer) {
        if(Mage::getModel('core/cookie')->get('es_cart_items') != false) {
            // Get all items in cart 
            $order = Mage::getModel('sales/order')->load($observer->getData('order_ids')[0]);
            $items_in_cart = json_decode(Mage::getModel('core/cookie')->get('es_cart_items'));
            $this->orders = new stdClass();

            // iterate through all items in the order
            foreach($order->getAllItems() as $order_item) {
                // Unserialize item data
                $item = unserialize($order_item->product_options);

                // Check whether ordered item exists in cart
                if(isset($item['simple_sku']) && isset($items_in_cart->$item['simple_sku'])) {
                    $size_attributes = array();
                    foreach(explode(',', Mage::getStoreConfig('sizeguide/sizeguide/sizeguide_size_attributes')) as $attribute) {
                        $sattr = Mage::getSingleton('eav/config')->getAttribute('catalog_product', $attribute);
                        $size_attributes[] = $sattr->getFrontendLabel();
                    }
                    
                    foreach ($item['attributes_info'] as $value) {
                        if (in_array($value['label'], $size_attributes)) {
                            $curl = curl_init("https://popup.easysize.me/collect?a=24&v=".urlencode($value['value'])."&pv={$items_in_cart->$item['simple_sku']}");
                            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                            curl_exec($curl);
                            curl_close($curl);
                        }
                    }
                }
            }

            Mage::getModel('core/cookie')->delete('es_cart_items', '/');
            Mage::getModel('core/cookie')->set('es_cart_items', '', 0, '/');
        }
    }

    public function addToCart($observer) {
        $item_data = $observer->getEvent()->getQuoteItem()->getProduct()->getData();
        if(Mage::getModel('core/cookie')->get('esui') && Mage::getModel('core/cookie')->get('espageview')) {
            if(Mage::getModel('core/cookie')->get('es_cart_items') != false) {
                $items_in_cart = json_decode(Mage::getModel('core/cookie')->get('es_cart_items'));
            } else {
                $items_in_cart = new stdClass();
            }

            $items_in_cart->$item_data['sku'] = Mage::getModel('core/cookie')->get('espageview');
            Mage::getModel('core/cookie')->set('es_cart_items', json_encode($items_in_cart), 2678400, '/');
        }
    }

    public function updateCart($observer) {
        // todo. Fix update cart
    }

    public function filterProducts($observer)
    {
        if(isset($_REQUEST['easysize_sizefilter_products'])) {
            $observer->getEvent()->getCollection()->addAttributeToFilter('entity_id', array('in' => $_REQUEST['easysize_sizefilter_products']));
        }

        if (Mage::registry('easysize_sizefilter_applied') || Mage::getStoreConfig('sizeguide/sizefilter/sizefilter_enabled') != 1) { return; }

        // Get product attribute names for data extraction
        $shop_configuration = Mage::getStoreConfig('sizeguide/sizeguide');
        $gender_attribute_name = $shop_configuration['sizeguide_gender_attribute'];
        $easysize_shop_id = $shop_configuration['sizeguide_shopid'];

        if(isset($_COOKIE['esui'])) {
            $easysize_user_id = $_COOKIE['esui'];

            if(!is_numeric($easysize_user_id) || is_numeric($easysize_user_id) && $easysize_user_id < 0) {
                Mage::register('easysize_sizefilter_applied', true);
                return;
            }
        } else {
            Mage::register('easysize_sizefilter_applied', true);
            return;
        }

        $collection = $observer->getEvent()->getCollection()
            ->addAttributeToSelect('manufacturer');
        $collection_product_ids = $collection->getAllIds();
        $first_item = Mage::getModel('catalog/product')->getCollection()
            ->addAttributeToSelect($gender_attribute_name)
            ->addAttributeToFilter('entity_id', array('in' => $collection_product_ids))
            ->addAttributeToFilter('type_id', 'configurable')
            ->getFirstItem();

        $current_store = Mage::app()->getRequest()->getParam('store');

        //  Using first item from collection, get gender and category of items
        if(strlen($shop_configuration['sizeguide_gender_one_attribute']) > 0) {
            $product_gender = $shop_configuration['sizeguide_gender_one_attribute'];
        } else {
            $product_gender = $first_item->getAttributeText($gender_attribute_name);
        }

        $params = [];
        $params[] = "shop_id={$easysize_shop_id}";
        $params[] = "gender={$product_gender}";
        $params[] = "easysize_user_id={$easysize_user_id}";

        if($first_item->getCategory() && $product_type = $first_item->getCategory()->getName()) {
            $product_type_decoded = urlencode($product_type);

            if($this->isCategoryABrand($first_item->getCategory())) {
                $params[] = "brand={$product_type_decoded}";
            } else {
                $params[] = "category={$product_type_decoded}";
            }
        } else {
            /*
            If product attribute extraction fails, register a notice to not have an overload
            */
            Mage::register('easysize_sizefilter_applied', true);
            return;
        }

        $params = implode('&', $params);
        $url = "https://popup.easysize.me/filterProductsBySize?${params}";
        $curl = curl_init();
        curl_setopt_array($curl, array( CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $url ));
        $data = json_decode(curl_exec($curl), true);
        curl_close($curl);

        $filtered_product_ids = [];
        foreach ($data as $item) {
            $filtered_product_ids[] = $item['product_id'];
        }

        if(sizeof($filtered_product_ids) > 0) {
            $_REQUEST['easysize_sizefilter_has_products'] = true;

            if(!isset($_POST['easysize_sizefilter'])) { $_POST['easysize_sizefilter'] = ''; }

            if (
                isset($_POST['easysize_sizefilter']) && $_POST['easysize_sizefilter'] == "enable"
                || isset($_SESSION['easysize_sizefilter']) && $_SESSION['easysize_sizefilter'] == 1 && $_POST['easysize_sizefilter'] != "disable") {

                $_SESSION['easysize_sizefilter'] = 1;
                $_REQUEST['easysize_sizefilter_applied'] = true;
                $_REQUEST['easysize_sizefilter_products'] = $filtered_product_ids;
            } elseif(isset($_POST['easysize_sizefilter']) && $_POST['easysize_sizefilter'] === "disable") {
                $_SESSION['easysize_sizefilter'] = 0;
            }
        }

        Mage::register('easysize_sizefilter_applied', true);
    }

    private function isCategoryABrand($category) {
        $brandFromCategory = Mage::getModel('catalog/category')->load(Mage::getStoreConfig('sizeguide/sizeguide/sizeguide_brand_attribute'));
        if($brandFromCategory->getId()) {
            $_parentCategories = $category->getParentCategories();
            foreach($_parentCategories as $_parentCategory) {
                if($brandFromCategory->getId() != $_parentCategory->getId() && in_array($brandFromCategory->getId(), $_parentCategory->getPathIds())) {
                    return true;
                }
            }
        } else {
            return false;
        }
    }
}