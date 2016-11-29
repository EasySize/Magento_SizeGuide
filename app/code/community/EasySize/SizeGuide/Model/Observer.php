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
                            $params[] = "a=24";
                            $params[] = "v=".urlencode($value['value']);
                            $params[] = "v3=".urlencode($order->getIncrementId());
                            if($order->getCustomerId()) {
                                $params[] = "v4=".urlencode($order->getCustomerId());
                            }
                            $params[] = "pv={$items_in_cart->$item['simple_sku']}";
                            $params = implode("&", $params);
                            $curl = curl_init("https://popup.easysize.me/collect?${params}");
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

    public function updateProductStock($observer) {
        $sizes_in_stock = array();
        $size_attribute_codes = Mage::getStoreConfig('sizeguide/sizeguide/sizeguide_size_attributes');
        $shop_id = Mage::getStoreConfig('sizeguide/sizeguide/sizeguide_shopid');

        $product = $observer->getData('product');
        $product_id = $product->getId();
        $product_attributes = Mage::getModel('eav/config')
            ->getEntityAttributeCodes(Mage_Catalog_Model_Product::ENTITY,$product);

        if($product->getTypeId() == 'configurable') {
            $child_products = Mage::getModel('catalog/product_type_configurable')->getUsedProducts(null, $product);

            // Iterate through all simple products
            foreach ($child_products as $simple_product) {
                // Iterate through all size attributes
                foreach (explode(',', $size_attribute_codes) as $size_attribute) {
                    if (in_array($size_attribute, $product_attributes)) {
                        $current_simple_product_id = $simple_product->getId();
                        $current_simple_product = Mage::getModel('catalog/product')->load($current_simple_product_id);
                        $quantity = $current_simple_product->getStockItem()->getQty();
                        $size = $current_simple_product->getAttributeText($size_attribute);
                        $sizes_in_stock[$size] = $quantity;
                    }
                }
            }
        } else if($product->getTypeId() == 'simple') {
            foreach (explode(',', $size_attribute_codes) as $size_attribute) {
                if (in_array($size_attribute, $product_attributes)) {
                    $current_simple_product_id = $product->getId();
                    $current_simple_product = Mage::getModel('catalog/product')->load($current_simple_product_id);
                    $quantity = $current_simple_product->getStockItem()->getQty();
                    $size = $current_simple_product->getAttributeText($size_attribute);
                    $sizes_in_stock[$size] = $quantity;
                }
            }

            $product_id = Mage::getModel('catalog/product_type_configurable')->getParentIdsByChild($product->getId())[0];
        }

        if($product_id) {
            $data = json_encode(array("sizes_in_stock" => $sizes_in_stock));
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://popup.easysize.me/api/{$shop_id}/product_stock/{$product_id}");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: '.strlen($data)));
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS,$data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response  = curl_exec($ch);
        }
    }
}