<?php

class EasySize_SizeFilter_Model_Observer
{
    public function filterProducts($observer)
    {
        if (($_POST['easysize_sizefilter'] == "enable" || $_SESSION['easysize_sizefilter'] == 1 && $_POST['easysize_sizefilter'] != "disable")) {
            if (!isset($_REQUEST['easysize_sizefilter_applied']) && $_REQUEST['easysize_sizefilter_applied'] != true) {
                $_SESSION['easysize_sizefilter'] = 1;

                $collection = $observer->getEvent()->getCollection()
                    ->addAttributeToSelect('manufacturer');
                $collection_product_ids = $collection->getAllIds();
                $first_item = Mage::getModel('catalog/product')->getCollection()
                                    ->addAttributeToSelect('gender')
                                    ->addAttributeToFilter('entity_id', array('in' => $collection_product_ids))
                                    ->addAttributeToFilter('type_id', 'configurable')
                                    ->getFirstItem();

                $current_store = Mage::app()->getRequest()->getParam('store');

                //  Using first item from collection, get gender and category of items
                $product_gender = $first_item->getAttributeText('gender');
                $product_type = $first_item->getCategory()->getName();
                $product_type_decoded = urlencode($product_type);

                $params = [];
                $params[] = "shop_id=ag8aGasg87";
                $params[] = "category={$product_type_decoded}";
                $params[] = "gender={$product_gender}";
                $params[] = "easysize_user_id={$_COOKIE['esui']}";
                $params = implode('&', $params);
                $url = "http://localhost:8000/filterProductsBySize?${params}";
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_URL => $url
                ));
                $data = json_decode(curl_exec($curl), true);
                curl_close($curl);

                $filtered_product_ids = [];
                foreach ($data as $item) {
                    $filtered_product_ids[] = $item['product_id'];
                }

                $_REQUEST['easysize_sizefilter_applied'] = true;
                $observer->getEvent()->getCollection()->addAttributeToFilter('entity_id', array('in' => $filtered_product_ids));
            }
        } elseif($_POST['easysize_sizefilter'] === "disable") {
            $_SESSION['easysize_sizefilter'] = 0;
        }
    }
}