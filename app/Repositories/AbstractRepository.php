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

use Espo\Core\Exceptions\Error;
use Espo\Core\Templates\Repositories\Base;
use Espo\ORM\Entity;

/**
 * Class AbstractRepository
 */
abstract class AbstractRepository extends Base
{
    protected function prepareAssets(Entity $entity, array $result): array
    {
        $channelsIds = array_column($result, 'channel');

        $channels = [];
        if (!empty($channelsIds)) {
            $dbChannels = $this->getEntityManager()->getRepository('Channel')->select(['id', 'name'])->where(['id' => $channelsIds])->find()->toArray();
            $channels = array_column($dbChannels, 'name', 'id');
        }

        foreach ($result as $k => $v) {
            $result[$k]['entityName'] = $entity->getEntityType();
            $result[$k]['entityId'] = $entity->get('id');
            $result[$k]['scope'] = 'Global';
            $result[$k]['channelId'] = null;
            $result[$k]['channelName'] = null;
            if (!empty($v['channel']) && !empty($channels[$v['channel']])) {
                $result[$k]['scope'] = 'Channel';
                $result[$k]['channelId'] = $v['channel'];
                $result[$k]['channelName'] = $channels[$v['channel']];
            }
            $result[$k]['channel'] = '-';
        }

        return $result;
    }

    /**
     * @param Entity        $entity
     * @param Entity|string $foreign
     * @param array         $options
     *
     * @throws Error
     */
    protected function afterUnrelateAssets($entity, $foreign, $options): void
    {
        if (!in_array($entity->getEntityType(), ['Product', 'Category'])) {
            return;
        }

        if (is_string($foreign)) {
            $foreign = $this->getEntityManager()->getEntity('Asset', $foreign);
        }

        if ($entity->get('imageId') === $foreign->get('fileId')) {
            $entity->set('imageId', null);
            $this->getEntityManager()->saveEntity($entity);
        }
    }
}
