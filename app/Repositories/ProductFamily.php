<?php
/*
 * This file is part of AtroPIM.
 *
 * AtroPIM - Open Source PIM application.
 * Copyright (C) 2020 AtroCore UG (haftungsbeschränkt).
 * Website: https://atropim.com
 *
 * AtroPIM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * AtroPIM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with AtroPIM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "AtroPIM" word.
 *
 * This software is not allowed to be used in Russia and Belarus.
 */

declare(strict_types=1);

namespace Pim\Repositories;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;

/**
 * Class ProductFamily
 */
class ProductFamily extends AbstractRepository
{
    /**
     * @var string
     */
    protected $ownership = 'fromProductFamily';

    /**
     * @var string
     */
    protected $ownershipRelation = 'Product';

    /**
     * @var string
     */
    protected $assignedUserOwnership = 'assignedUserProductOwnership';

    /**
     * @var string
     */
    protected $ownerUserOwnership = 'ownerUserProductOwnership';

    /**
     * @var string
     */
    protected $teamsOwnership = 'teamsProductOwnership';

    public static function isAdvancedClassificationInstalled(): bool
    {
        return class_exists(\AdvancedClassification\Module::class);
    }

    public static function onlyForAdvancedClassification(): void
    {
        if (!self::isAdvancedClassificationInstalled()) {
            throw new BadRequest("Advanced Classification module isn't installed.");
        }
    }

    public function getParentsIds(Entity $entity, array $ids = []): array
    {
        self::onlyForAdvancedClassification();
        $ids = [];
        if (!empty($entity->get('parentId'))) {
            $ids = array_unique(array_merge($ids, $this->getParentsIds($entity->get('parent'), $ids)));
            $ids[] = $entity->get('parentId');
        }

        return $ids;
    }

    public function getChildrenIds(Entity $entity, array $ids = []): array
    {
        self::onlyForAdvancedClassification();
        $ids = [];

        $children = $entity->get('children');
        if (!empty($children) && count($children) > 0) {
            foreach ($children as $child) {
                $ids = array_unique(array_merge($ids, $this->getChildrenIds($child, $ids)));
                $ids[] = $child->get('id');
            }
        }

        return $ids;
    }

    /**
     * @param string $id
     * @param string $scope
     *
     * @return array
     */
    public function getLinkedAttributesIds(string $id, string $scope = 'Global'): array
    {
        $data = $this
            ->getEntityManager()
            ->getRepository('ProductFamilyAttribute')
            ->select(['attributeId'])
            ->where(['productFamilyId' => $id, 'scope' => $scope])
            ->find()
            ->toArray();

        return array_column($data, 'attributeId');
    }

    /**
     * @param array       $productFamiliesIds
     * @param string|null $attributeGroupId
     *
     * @return array
     */
    public function getLinkedWithAttributeGroup(array $productFamiliesIds, ?string $attributeGroupId): array
    {
        $data = $this
            ->getEntityManager()
            ->getRepository('ProductFamilyAttribute')
            ->select(['id'])
            ->distinct()
            ->join('attribute')
            ->where(
                [
                    'productFamilyId'            => $productFamiliesIds,
                    'attribute.attributeGroupId' => ($attributeGroupId != '') ? $attributeGroupId : null
                ]
            )
            ->find()
            ->toArray();

        return array_column($data, 'id');
    }

    protected function beforeSave(Entity $entity, array $options = [])
    {
        if (!$this->isCodeValid($entity)) {
            throw new BadRequest($this->getInjection('language')->translate('codeIsInvalid', 'exceptions', 'Global'));
        }

        parent::beforeSave($entity, $options);
    }

    protected function afterSave(Entity $entity, array $options = array())
    {
        parent::afterSave($entity, $options);

        $this->setInheritedOwnership($entity);
    }

    protected function afterRemove(Entity $entity, array $options = [])
    {
        parent::afterRemove($entity, $options);

        $this->getPDO()->exec("UPDATE `product` SET product_family_id=NULL WHERE product_family_id='{$entity->get('id')}'");
        $this->getPDO()->exec("DELETE FROM `product_family_attribute` WHERE product_family_id='{$entity->get('id')}'");
    }

    protected function init()
    {
        parent::init();

        $this->addDependency('language');
    }

    protected function isCodeValid(Entity $entity): bool
    {
        if (!$entity->isAttributeChanged('code')) {
            return true;
        }

        if (!empty($entity->get('code')) && preg_match(self::CODE_PATTERN, $entity->get('code'))) {
            $exists = $this->where(['code' => $entity->get('code')])->findOne();
            if (!empty($exists) && $exists->get('id') !== $entity->get('id')) {
                return false;
            }
        }

        return true;
    }
}
