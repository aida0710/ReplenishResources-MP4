<?php

namespace aieuo\replenish;

use pocketmine\scheduler\Task;
use pocketmine\world\Position;

class AutoReplenishTask extends Task {
    /** @noinspection ReturnTypeCanBeDeclaredInspection */
    public function onRun(): void {
        $api = ReplenishResourcesAPI::getInstance();
        foreach ($api->getAutoReplenishResources() as $place) {
            if ($api->getSetting()->get("announcement")) {
                $api->getOwner()->getServer()->broadcastMessage("資源(".$place.")の補充を行います");
            }
            $pos = explode(",", $place);
            $api->replenish(new Position((int)$pos[0], (int)$pos[1], (int)$pos[2], $api->getOwner()->getServer()->getWorldManager()->getWorldByName($pos[3])));
        }
    }
}