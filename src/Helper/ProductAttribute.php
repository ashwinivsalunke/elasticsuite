<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile Elastic Suite to newer
 * versions in the future.
 *
 * @category  Smile
 * @package   Smile_ElasticSuiteCatalog
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2016 Smile
 * @license   Open Software License ("OSL") v. 3.0
 */

namespace Smile\ElasticSuiteCatalog\Helper;

use Smile\ElasticSuiteCore\Helper\Mapping;
use Magento\Framework\App\Helper\Context;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Smile\ElasticSuiteCore\Api\Index\Mapping\FieldInterface;

/**
 *
 * @category Smile
 * @package  Smile_ElasticSuiteCatalog
 * @author   Aurelien FOUCRET <aurelien.foucret@smile.fr>
 */
class ProductAttribute extends Mapping
{
    /**
     * @var \Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory
     */
    private $attributeFactory;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory
     */
    private $attributeCollectionFactory;

    /**
     * @var array
     */
    private $storeAttributes = [];

    /**
     * @var array
     */
    private $attributeOptionTextCache = [];

    /**
     *
     * @param Context                    $context                    Helper context.
     * @param AttributeCollectionFactory $attributeCollectionFactory Factory used to create attribute collections.
     * @param AttributeFactory           $attributeFactory           Factory used to create attributes.
     */
    public function __construct(
        Context $context,
        AttributeCollectionFactory $attributeCollectionFactory,
        AttributeFactory $attributeFactory
    ) {
        parent::__construct($context);
        $this->attributeFactory           = $attributeFactory;
        $this->attributeCollectionFactory = $attributeCollectionFactory;
    }

    /**
     * Retrieve a new product attribute collection.
     *
     * @return Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection
     */
    public function getAttibuteCollection()
    {
        return $this->attributeCollectionFactory->create();
    }

    /**
     * Parse attribute to get mapping field creation parameters.
     *
     * @param ProductAttributeInterface $attribute Product attribute.
     *
     * @return array
     */
    public function getMappingFieldOptions(ProductAttributeInterface $attribute)
    {
        $options = [
            'isSearchable'         => $attribute->getIsSearchable(),
            'isFilterable'         => $attribute->getIsFilterable(),
            'isFilterableInSearch' => $attribute->getIsFilterable(),
            'searchWeight'         => $attribute->getSearchWeight(),
        ];

        return $options;
    }

    /**
     * Get mapping field type for an attribute.
     *
     * @param ProductAttributeInterface $attribute Product attribute.
     *
     * @return string
     */
    public function getFieldType(ProductAttributeInterface $attribute)
    {
        $type = FieldInterface::FIELD_TYPE_STRING;

        if ($attribute->getBackendType() == 'int' || $attribute->getFrontendClass() == 'validate-digits') {
            $type = FieldInterface::FIELD_TYPE_INTEGER;
        } elseif ($attribute->getBackendType() == 'decimal' || $attribute->getFrontendClass() == 'validate-number') {
            $type = FieldInterface::FIELD_TYPE_DOUBLE;
        } elseif ($attribute->getSourceModel() == 'eav/entity_attribute_source_boolean') {
            $type = FieldInterface::FIELD_TYPE_BOOLEAN;
        } elseif ($attribute->getBackendType() == 'datetime') {
            $type = FieldInterface::FIELD_TYPE_DATE;
        } elseif ($attribute->usesSource() && $attribute->getSourceModel() === null) {
            $type = FieldInterface::FIELD_TYPE_INTEGER;
        }

        return $type;
    }

    /**
     * Parse attribute raw value (as saved in the database) to prepare the indexed value.
     *
     * For attribute using options the option value is also added to the result which contains two keys :
     *   - one is "attribute_code" and contained the option id(s)
     *   - the other one is "option_text_attribute_code" and contained option value(s)
     *
     * All value are transformed into arays to have a more simple management of
     * multivalued attributes merging on composite products).
     * ES doesn't care of having array of int when it an int is required.
     *
     * @param ProductAttributeInterface $attribute Product attribute.
     * @param integer                   $storeId   Store id.
     * @param mixed                     $value     Raw value to be parsed.
     *
     * @return array
     */
    public function prepareIndexValue(ProductAttributeInterface $attribute, $storeId, $value)
    {
        $attributeCode = $attribute->getAttributeCode();
        $values = [];

        $simpleValueMapper = function ($value) use ($attribute) {
            return $this->prepareSimpleIndexAttributeValue($attribute, $value);
        };

        if ($attribute->usesSource() && !is_array($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        $values[$attributeCode] = $value = array_filter(array_map($simpleValueMapper, $value));

        if ($attribute->usesSource()) {
            $optionTextFieldName = $this->getOptionTextFieldName($attributeCode);
            $values[$optionTextFieldName] = array_filter($this->getIndexOptionsText($attribute, $storeId, $value));
        }

        return array_filter($values);
    }

    /**
     * Transform an array of options ids into an arrays of option values for attribute that uses a source.
     * Values are localized for a store id.
     *
     * @param ProductAttributeInterface $attribute Product attribute.
     * @param integer                   $storeId   Store id
     * @param array                     $optionIds Array of options ids.
     *
     * @return array
     */
    public function getIndexOptionsText(ProductAttributeInterface $attribute, $storeId, array $optionIds)
    {
        $mapper = function ($optionId) use ($attribute, $storeId) {
            return $this->getIndexOptionText($attribute, $storeId, $optionId);
        };
        $optionValues = array_map($mapper, $optionIds);

        return $optionValues;
    }

    /**
     * Transform an options id into an array of option value for attribute that uses a source.
     * Value islocalized for a store id.
     *
     * @param ProductAttributeInterface $attribute Product attribute.
     * @param integer                   $storeId   Store id.
     * @param string|integer            $optionId  Option id.
     *
     * @return string|boolean
     */
    public function getIndexOptionText(ProductAttributeInterface $attribute, $storeId, $optionId)
    {
        $attribute   = $this->getAttributeByStore($attribute, $storeId);
        $attributeId = $attribute->getAttributeId();

        if (!isset($this->attributeOptionTextCache[$storeId])) {
            $this->attributeOptionTextCache[$storeId] = [];
        }

        if (!isset($this->attributeOptionTextCache[$storeId])) {
            $this->attributeOptionTextCache[$storeId][$attributeId] = [];
        }

        if (!isset($this->attributeOptionTextCache[$storeId][$attributeId][$optionId])) {
            $optionValue = $attribute->getSource()->getIndexOptionText($optionId);
            $this->attributeOptionTextCache[$storeId][$attributeId][$optionId] = $optionValue;
        }

        return $this->attributeOptionTextCache[$storeId][$attributeId][$optionId];
    }

    /**
     * Ensure types of numerical values is correct before indexing.
     *
     * @param ProductAttributeInterface $attribute Product attribute.
     * @param mixed                     $value     Raw value.
     *
     * @return mixed
     */
    private function prepareSimpleIndexAttributeValue(ProductAttributeInterface $attribute, $value)
    {
        if ($attribute->getBackendType() == 'decimal') {
            $value = floatval($value);
        } elseif ($attribute->getBackendType() == 'int') {
            $value = intval($value);
        }

        return $value;
    }

    /**
     * Load the localized version of an attribute.
     * This code uses a local cache to ensure correct performance during indexing.
     *
     * @param ProductAttributeInterface|int $attribute Product attribute.
     * @param integer                       $storeId   Store id.
     *
     * @return \Magento\Catalog\Api\Data\ProductAttributeInterface
     */
    private function getAttributeByStore($attribute, $storeId)
    {
        $storeAttribute = false;
        $attributeId = $this->getAttributeId($attribute);

        if (!isset($this->storeAttributes[$storeId]) || !isset($this->storeAttributes[$storeId][$attributeId])) {
            /**
             * @var ProductAttributeInterface
             */
            $storeAttribute = $this->attributeFactory->create();
            $storeAttribute->setStoreId($storeId)
                ->load($attributeId);

            $this->storeAttributes[$storeId][$attributeId] = $storeAttribute;
        }

        return $this->storeAttributes[$storeId][$attributeId];
    }

    /**
     * This util method is used to ensure the attribute is an integer and uses it's id if it is an object.
     *
     * @param \Magento\Catalog\Api\Data\ProductAttributeInterface|integer $attribute Product attribute.
     *
     * @return integer
     */
    private function getAttributeId($attribute)
    {
        $attributeId = $attribute;

        if (is_object($attribute)) {
            $attributeId = $attribute->getAttributeId();
        }

        return $attributeId;
    }
}
