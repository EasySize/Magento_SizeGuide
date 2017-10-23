<?php

/*
 * Get a list of all attributes in the shop.
 * Used in the configuration(system.xml) to select values.
 */ 

class EasySize_SizeGuide_Model_ShopAttributes {

    public function toOptionArray() {
        $result = Array();
        $shopAttributes = Mage::getResourceModel('catalog/product_attribute_collection')
                          ->getItems();

        foreach ($shopAttributes as $attribute) {
            $code = $attribute->getAttributeCode();
            $label = $attribute->getFrontendLabel();
            $result[] = array('value'=>$code, 'label'=>$label);
        }

        $shopCategories = Mage::getModel('catalog/category')
            ->getCollection()
            ->addAttributeToSelect('name');

        foreach($shopCategories as $category) {
            $id = $category->getId();
            $label = $category->getName();

            $result[] = array('value'=>$id, 'label'=>$label);
        }

        return $result;
    }
}
