<?php

namespace aieuo\replenish;

use pocketmine\scheduler\Task;
use pocketmine\world\Position;

class AutoReplenishTask extends Task {
    /** @noinspection ReturnTypeCanBeDeclaredInspection */
    public function onRun(): void {
        $api = ReplenishResourcesAPI::getInstance();

        foreach ($api->getAutoReplenishResources() as $place) {
            $resource = $api->getResource($place->getPosition());
            $count = ReplenishResources::getInstance()->countBlockAll($resource);
            if ($api->getSetting()->get("announcement")) {
                $api->getOwner()->getServer()->broadcastMessage(ReplenishResources::Prefix ."{$place->getPosition()->getWorld()->getFolderName()}ワールドの{$place->getName()}資源({$count}ブロック)の補充を実行しました");
            }
            $pos = explode(",", $place);
            $api->replenish(new Position((int)$pos[0], (int)$pos[1], (int)$pos[2], $api->getOwner()->getServer()->getWorldManager()->getWorldByName($pos[3])));
        }
    }
}