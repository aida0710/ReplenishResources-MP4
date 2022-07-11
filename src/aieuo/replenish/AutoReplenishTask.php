<?php

namespace aieuo\replenish;

use pocketmine\block\BlockFactory;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\world\Position;

class AutoReplenishTask extends Task {

    /** @noinspection ReturnTypeCanBeDeclaredInspection */
    public function onRun(): void {
        $api = ReplenishResourcesAPI::getInstance();
        foreach ($api->getAutoReplenishResources() as $place) {
            $pos = explode(",", $place);
            $resource = $api->getResource(new Position((int)$pos[0], (int)$pos[1], (int)$pos[2], Server::getInstance()->getWorldManager()->getWorldByName($pos[3])));
            if ($resource === null) return;
            $placeBlock = null;
            foreach ($resource["id"] as $id) {
                $placeBlock = BlockFactory::getInstance()->get((int)$id["id"], (int)$id["damage"]);
            }
            if (is_null($placeBlock)) return;
            $count = ReplenishResources::getInstance()->countBlockAll($resource);
            if ($api->getSetting()->get("announcement")) {
                $api->getOwner()->getServer()->broadcastMessage(ReplenishResources::Prefix . "{$pos[3]}ワールドの{$placeBlock->getName()}資源({$count}ブロック)の補充を実行しました");
            }
            $api->replenish(new Position((int)$pos[0], (int)$pos[1], (int)$pos[2], $api->getOwner()->getServer()->getWorldManager()->getWorldByName($pos[3])));
        }
    }
}