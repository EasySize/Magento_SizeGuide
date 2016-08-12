<?php

class EasySize_SizeGuide_Block_Data extends Mage_Core_Block_Template {
    private $size_attribute_id = false;

    public function getRequiredAttributes() {
        $required_attributes = new stdClass();

        // product is currently viewed product
        $product_id = $this->getRequest()->getParam('id');
        $product = Mage::getModel('catalog/product')->load($product_id);

        if($product->getTypeId() != 'configurable') {
            return json_encode($required_attributes);
        }

        // Get all sizeguide settings from shop configurations
        $shop_configuration = Mage::getStoreConfig('sizeguide/sizeguide');

        $required_attributes->order_button_id = $shop_configuration['sizeguide_add_to_cart_button'];
        $required_attributes->product_id = $product_id;

        // This parts distinguishes whether the brand is saved as category or product attribute
        $brandFromCategory = Mage::getModel('catalog/category')->load($shop_configuration['sizeguide_brand_attribute']);
        if($brandFromCategory->getId()) {
            $required_attributes->product_brand = $this->getProductBrandFromCategory($product, $brandFromCategory->getId());
        } else {
            $required_attributes->product_brand = $product->getAttributeText($shop_configuration['sizeguide_brand_attribute']);
        }

        $required_attributes->product_gender = isset($shop_configuration['sizeguide_gender_attribute']) ? $product->getAttributeText($shop_configuration['sizeguide_gender_attribute']) : '';
        $one_gender = isset($shop_configuration['sizeguide_gender_one_attribute']) ? $shop_configuration['sizeguide_gender_one_attribute'] : '';

        // If one gender is set, the shop is selling on gender clothing items
        if(strlen($one_gender) > 0) {
            $required_attributes->product_gender = $one_gender;
        }

        $required_attributes->product_type = implode(',', $this->getProductCategoriesNames($product));
        $required_attributes->sizes_in_stock = $this->getProductSizesInStock($product, $shop_configuration['sizeguide_size_attributes']);
        $required_attributes->shop_id = $shop_configuration['sizeguide_shopid'];
        $required_attributes->placeholder = $shop_configuration['sizeguide_button_placeholder'];
        $required_attributes->size_selector = "attribute{$this->size_attribute_id}";
        $required_attributes->user_id = $this->getCustomerId();
        $required_attributes->image_url = Mage::getModel('catalog/product_media_config')->getMediaUrl($product->getImage());
        
        return json_encode($required_attributes);
    }

    /*
     * Returns product brand if the brand is amongst categories
     */
    private function getProductBrandFromCategory($product, $parentCategoryIdToLookFor) {
        $categoryCollection = $product->getCategoryCollection();

        foreach($categoryCollection as $_productCategory) {
            $_parentCategories = $_productCategory->getParentCategories();

            foreach($_parentCategories as $_parentCategory) {
                if($parentCategoryIdToLookFor != $_parentCategory->getId() && in_array($parentCategoryIdToLookFor, $_parentCategory->getPathIds())) {
                    return $_parentCategory->getName();
                }
            }
        }

        return null;
    }

    /*
     * Returns all product category names
     */
    private function getProductCategoriesNames($product) {
        $all_product_categories = array();

        foreach($product->getCategoryCollection()->addAttributeToSelect('name') as $category) {
            $all_product_categories[] = $category->getName();
        }

        return $all_product_categories;
    }

    /*
     * Iterates through all the simple products created from the configurable product
     * Returns array of products' sizes in stock.
     */
    private function getProductSizesInStock($product, $size_attribute_codes) {
        $sizes_in_stock = array();

        if($product->getTypeId() == 'configurable') {
            $child_products = Mage::getModel('catalog/product_type_configurable')->getUsedProducts(null, $product);
            $product_attributes = Mage::getModel('eav/config')
                            ->getEntityAttributeCodes(Mage_Catalog_Model_Product::ENTITY,$product);

            // Iterate through all simple products
            foreach ($child_products as $simple_product) {
                // Iterate through all size attributes
                foreach (explode(',', $size_attribute_codes) as $size_attribute) {
                    if(in_array($size_attribute, $product_attributes)) {
                        $current_simple_product_id = $simple_product->getId();
                        $current_simple_product = Mage::getModel('catalog/product')->load($current_simple_product_id);
                        $quantity = $current_simple_product->getStockItem()->getQty();
                        $size = $current_simple_product->getAttributeText($size_attribute); 
                        $sizes_in_stock[$size] = $quantity;

                        /* When looking for size attribute id, 
                         * make sure there is atleast one item of that attribute, 
                         * and attribute is actually set
                        */
                        if(!$this->size_attribute_id && $quantity > 0 && strlen($size) > 0) {
                            $this->size_attribute_id = Mage::getResourceModel('eav/entity_attribute')
                                                    ->getIdByCode('catalog_product', $size_attribute);
                        }
                    }
                }
            }
        }
            

        return $sizes_in_stock;
    }

    /*
     * Returns logged in customer id || -1
     */
    private function getCustomerId() {
        if(Mage::getSingleton('customer/session')->isLoggedIn()) {
            return Mage::getSingleton('customer/session')->getCustomerId();
        } else {
            return -1;
        }
    }

    /*
     * Custom debugging function. Not used in production
     */
    public function log($text) {
        Mage::log($text, null, 'sizeguide.log');
    }
}