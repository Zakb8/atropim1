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
 */

declare(strict_types=1);

namespace Pim\Repositories;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Templates\Repositories\Base;
use Espo\ORM\Entity;
use Pim\Core\Exceptions\ProductAttributeAlreadyExists;

class ProductFamilyAttribute extends Base
{
    public function createProductAttributeValues(Entity $pfa): void
    {
        $productFamily = $pfa->get('productFamily');
        foreach ($productFamily->get('products') as $product) {
            $pav = $this->getPavRepository()->get();
            $pav->set('productId', $product->get('id'));
            $pav->set('attributeId', $pfa->get('attributeId'));
            $pav->set('isRequired', $pfa->get('isRequired'));
            $pav->set('scope', $pfa->get('scope'));
            $pav->set('channelId', $pfa->get('channelId'));
            $this->getEntityManager()->saveEntity($pav);
        }
    }

    public function updateProductAttributeValues(Entity $pfa): void
    {
        $productFamily = $pfa->get('productFamily');
        foreach ($productFamily->get('products') as $product) {
            $where = [
                'productId'   => $product->get('id'),
                'attributeId' => $pfa->get('attributeId'),
                'scope'       => $pfa->getFetched('scope')
            ];

            if ($pfa->getFetched('scope') === 'Channel') {
                $where['channelId'] = $pfa->getFetched('channelId');
            }

            if (!empty($pav = $this->getPavRepository()->where($where)->findOne())) {
                $pav->set('scope', $pfa->get('scope'));
                if ($pfa->get('scope') === 'Channel') {
                    $pav->set('channelId', $pfa->get('channelId'));
                } else {
                    $pav->set('channelId', null);
                }
                if (!empty($pfa->getFetched('isRequired')) == !empty($pav->get('isRequired'))) {
                    $pav->set('isRequired', $pfa->get('isRequired'));
                }
                $this->getEntityManager()->saveEntity($pav);
            }
        }
    }

    public function deleteProductAttributeValues(Entity $pfa): void
    {
        $productFamily = $pfa->get('productFamily');
        foreach ($productFamily->get('products') as $product) {
            $where = [
                'productId'   => $product->get('id'),
                'attributeId' => $pfa->get('attributeId'),
                'scope'       => $pfa->get('scope')
            ];

            if ($pfa->get('scope') === 'Channel') {
                $where['channelId'] = $pfa->get('channelId');
            }

            if (!empty($pav = $this->getPavRepository()->where($where)->findOne())) {
                $this->getEntityManager()->removeEntity($pav);
            }
        }
    }

    public function save(Entity $entity, array $options = [])
    {
        $this->getEntityManager()->getPDO()->beginTransaction();
        try {
            if ($entity->isNew()) {
                $this->createProductAttributeValues($entity);
            } else {
                $this->updateProductAttributeValues($entity);
            }
            $result = parent::save($entity, $options);
            $this->getEntityManager()->getPDO()->commit();
        } catch (\Throwable $e) {
            if (!($e instanceof ProductAttributeAlreadyExists && $entity->isNew())) {
                $this->getEntityManager()->getPDO()->rollBack();
                throw $e;
            }
        }

        return $result;
    }

    public function remove(Entity $entity, array $options = [])
    {
        $this->getEntityManager()->getPDO()->beginTransaction();
        try {
            $this->deleteProductAttributeValues($entity);
            $result = parent::remove($entity, $options);
            $this->getEntityManager()->getPDO()->commit();
        } catch (\Throwable $e) {
            $this->getEntityManager()->getPDO()->rollBack();
            throw $e;
        }

        return $result;
    }

    public function beforeSave(Entity $entity, array $options = [])
    {
        if ($entity->get('scope') === 'Global') {
            $entity->set('channelId', null);
        }

        if (empty($options['skipValidation'])) {
            $this->isValid($entity);
        }

        parent::beforeSave($entity, $options);
    }

    /**
     * @param Entity $entity
     *
     * @throws BadRequest
     */
    protected function isValid(Entity $entity): void
    {
        if (!$entity->isNew() && $entity->isAttributeChanged('attributeId')) {
            throw new BadRequest($this->exception('attributeInheritedFromProductFamilyCannotBeChanged'));
        }

        if (empty($entity->get('productFamilyId')) || empty($entity->get('attributeId'))) {
            throw new BadRequest($this->exception('ProductFamily and Attribute cannot be empty'));
        }

        if (!$this->isUnique($entity)) {
            throw new BadRequest($this->exception($this->createUnUniqueValidationMessage($entity, $entity->get('channelId'))));
        }
    }

    /**
     * @param Entity      $entity
     * @param string|null $channelId
     *
     * @return string
     * @throws \Espo\Core\Exceptions\Error
     */
    protected function createUnUniqueValidationMessage(Entity $entity, string $channelId = null): string
    {
        $channelName = $entity->get('scope');
        if ($channelName == 'Channel') {
            $channel = $this->getEntityManager()->getEntity('Channel', $channelId);
            $channelName = !empty($channel) ? "'" . $channel->get('name') . "'" : '';
        }

        $message = $this->translate('productAttributeAlreadyExists', 'exceptions', 'ProductAttributeValue');
        $message = sprintf($message, $entity->get('attribute')->get('name'), $channelName);

        return $message;
    }

    /**
     * @param Entity $entity
     *
     * @return bool
     */
    protected function isUnique(Entity $entity): bool
    {
        $where = [
            'id!='            => $entity->get('id'),
            'productFamilyId' => $entity->get('productFamilyId'),
            'attributeId'     => $entity->get('attributeId'),
            'scope'           => $entity->get('scope'),
        ];
        if ($entity->get('scope') == 'Channel') {
            $where['channelId'] = $entity->get('channelId');
        }

        $item = $this
            ->getEntityManager()
            ->getRepository('ProductFamilyAttribute')
            ->select(['id'])
            ->where($where)
            ->findOne();

        return empty($item);
    }

    /**
     * @inheritDoc
     */
    protected function init()
    {
        $this->addDependency('language');
    }

    /**
     * @param string $key
     * @param string $label
     * @param string $scope
     *
     * @return string
     */
    protected function translate(string $key, string $label, string $scope = 'ProductFamilyAttribute'): string
    {
        return $this->getInjection('language')->translate($key, $label, $scope);
    }

    /**
     * @param string $key
     *
     * @return string
     */
    protected function exception(string $key): string
    {
        return $this->translate($key, 'exceptions');
    }

    protected function getPavRepository(): ProductAttributeValue
    {
        return $this->getEntityManager()->getRepository('ProductAttributeValue');
    }
}
