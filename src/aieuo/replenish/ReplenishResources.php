<?php

namespace aieuo\replenish;

use pocketmine\block\BlockFactory;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\TaskHandler;
use pocketmine\Server;
use pocketmine\utils\Config;

class ReplenishResources extends PluginBase implements Listener {

    private ?TaskHandler $taskHandler = null;
    private Config $setting;
    private ReplenishResourcesAPI $api;
    private static ReplenishResources $instance;

    /** @var string[] */
    private array $break;

    private array $pos1;
    private array $pos2;
    private array $tap;

    /** @var float[][] */
    private array $time;

    public const Prefix = "§a[資源補充]§f";

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        if (!file_exists($this->getDataFolder())) @mkdir($this->getDataFolder(), 0721, true);
        $this->setting = new Config($this->getDataFolder() . "setting.yml", Config::YAML, [
            "enable-wait" => true,
            "wait" => 60,
            "sneak" => true,
            "announcement" => false,
            "enable-count" => true,
            "count" => 0,
            "check-inside" => true,
            "period" => 1,
            "tick-place" => 100,
            "enable-auto-replenish" => true,
            "auto-replenish-time" => 3600,
            "auto-replenish-resources" => [],
        ]);
        $this->setting->save();
        $this->checkConfig();
        $this->api = new ReplenishResourcesAPI($this, $this->getConfig(), $this->setting);
        if ($this->setting->get("enable-auto-replenish")) {
            $time = (float)$this->setting->get("auto-replenish-time", 60) * 20;
            $this->startAutoReplenishTask($time);
        }
    }

    public function onDisable(): void {
        $this->setting->save();
    }

    public function checkConfig(): void {
        if (version_compare("2.4.0", $this->setting->get("version", ""), "<=")) return;
        $version = $this->getDescription()->getVersion();
        $this->setting->set("version", $version);
        $resources = [];
        foreach ($this->getConfig()->getAll() as $place => $resource) {
            if (isset($resource["id"]["id"]) or isset($resource["id"]["damage"])) {
                $resource["id"] = [
                    [
                        "id" => $resource["id"]["id"],
                        "damage" => $resource["id"]["damage"],
                        "per" => 1,
                    ]
                ];
            }
            $resources[$place] = $resource;
        }
        $this->getConfig()->setAll($resources);
        $this->getConfig()->save();
    }

    public function getSetting(): Config {
        return $this->setting;
    }

    public function startAutoReplenishTask(int $time): void {
        if ($time === 0) {
            if ($this->taskHandler instanceof TaskHandler and !$this->taskHandler->isCancelled()) {
                $this->taskHandler->cancel();
            }
            $this->taskHandler = null;
            return;
        }
        if ($this->taskHandler instanceof TaskHandler and !$this->taskHandler->isCancelled()) {
            if ($time === $this->taskHandler->getPeriod()) return;
            $this->taskHandler->cancel();
        }
        $this->taskHandler = $this->getScheduler()->scheduleRepeatingTask(new AutoReplenishTask(), $time);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$command->testPermission($sender)) return false;
        if (!isset($args[0])) {
            $sender->sendMessage(ReplenishResources::Prefix . "Usage: /reso <pos1 | pos2 | add | del | change | cancel | auto | setting>");
            return true;
        }
        $name = $sender->getName();
        switch ($args[0]) {
            case 'pos1':
            case 'pos2':
                if (!($sender instanceof Player)) {
                    $sender->sendMessage(ReplenishResources::Prefix . "コンソールからは使用できません");
                    return true;
                }
                $this->break[$name] = $args[0];
                $sender->sendMessage(ReplenishResources::Prefix . "ブロックを壊してください");
                break;
            case 'add':
                if (!($sender instanceof Player)) {
                    $sender->sendMessage(ReplenishResources::Prefix . "コンソールからは使用できません");
                    return true;
                }
                if (!isset($args[1])) {
                    $sender->sendMessage(ReplenishResources::Prefix . "Usage: /reso add <id>");
                    return true;
                }
                if (!isset($this->pos1[$name]) or !isset($this->pos2[$name])) {
                    $sender->sendMessage(ReplenishResources::Prefix . "まず/reso pos1と/reso pos2で範囲を設定してください");
                    return true;
                }
                if ($this->pos1[$name]->getWorld()->getFolderName() !== $this->pos2[$name]->getWorld()->getFolderName()) {
                    $sender->sendMessage(ReplenishResources::Prefix . "pos1とpos2は同じワールドに設定してください");
                    return true;
                }
                $this->tap[$name] = ["type" => "add", "id" => $args[1]];
                $sender->sendMessage(ReplenishResources::Prefix . "追加する看板をタップしてください");
                break;
            case 'del':
                if (!($sender instanceof Player)) {
                    $sender->sendMessage(ReplenishResources::Prefix . "コンソールからは使用できません");
                    return true;
                }
                $this->tap[$name] = ["type" => "del"];
                $sender->sendMessage(ReplenishResources::Prefix . "削除する看板をタップしてください");
                break;
            case "change":
                if (!($sender instanceof Player)) {
                    $sender->sendMessage(ReplenishResources::Prefix . "コンソールからは使用できません");
                    return true;
                }
                if (!isset($args[1])) {
                    $sender->sendMessage(ReplenishResources::Prefix . "Usage: /reso change <id>");
                    return true;
                }
                $this->tap[$name] = ["type" => "change", "id" => $args[1]];
                $sender->sendMessage(ReplenishResources::Prefix . "変更する看板をタップしてください");
                break;
            case 'cancel':
                if (!($sender instanceof Player)) {
                    $sender->sendMessage(ReplenishResources::Prefix . "コンソールからは使用できません");
                    return true;
                }
                unset($this->pos1[$name], $this->pos2[$name], $this->tap[$name], $this->break[$name]);
                $sender->sendMessage(ReplenishResources::Prefix . "キャンセルしました");
                break;
            case 'auto':
                if (!($sender instanceof Player)) {
                    $sender->sendMessage(ReplenishResources::Prefix . "コンソールからは使用できません");
                    return true;
                }
                if (!isset($args[1])) {
                    $sender->sendMessage(ReplenishResources::Prefix . "Usage: /reso auto <add | del>");
                    return true;
                }
                switch ($args[1]) {
                    case 'add':
                        $this->tap[$name] = ["type" => "auto_add"];
                        $sender->sendMessage(ReplenishResources::Prefix . "追加する看板をタップしてください");
                        break;
                    case 'del':
                        $this->tap[$name] = ["type" => "auto_del"];
                        $sender->sendMessage(ReplenishResources::Prefix . "削除する看板をタップしてください");
                        break;
                    default:
                        $sender->sendMessage(ReplenishResources::Prefix . "Usage: /reso auto <add | del>");
                        break;
                }
                break;
            case 'setting':
                if (empty($args[1])) {
                    $sender->sendMessage(ReplenishResources::Prefix . "サブコマンドを入力してください[Usage: /reso setting <sneak | announce | checkplayer | reload>]");
                    break;
                }
                switch ($args[1]) {
                    case "sneak":
                        $sneak = $this->getSetting()->get("sneak");
                        if (!isset($args[2])) {
                            $sender->sendMessage(ReplenishResources::Prefix . $sneak ? "スニークしないと反応しません" : "スニークしなくても反応します");
                            break;
                        }
                        if ($args[2] !== "on" and $args[2] !== "off") {
                            $sender->sendMessage(ReplenishResources::Prefix . "Usage: /reso setting sneak <on | off>");
                            break;
                        }
                        $this->getSetting()->set("sneak", $args[2] === "on");
                        $this->getSetting()->save();
                        $sender->sendMessage(ReplenishResources::Prefix . ($args[2] === "on" ? "スニークしないと反応しない" : "スニークしなくても反応する") . "ようにしました");
                        break;
                    case "announce":
                        $announce = $this->getSetting()->get("announce");
                        if (!isset($args[2])) {
                            $sender->sendMessage(ReplenishResources::Prefix . $announce ? "補充時に全員に知らせます" : "補充時に全員に知らせません");
                            break;
                        }
                        if ($args[2] !== "on" and $args[2] !== "off") {
                            $sender->sendMessage(ReplenishResources::Prefix . "Usage: /reso setting announce <on | off>");
                            break;
                        }
                        $this->getSetting()->set("announce", $args[2] === "on");
                        $this->getSetting()->save();
                        $sender->sendMessage(ReplenishResources::Prefix . ($args[2] === "on" ? "補充時に全員に知る" : "補充時に全員に知らせない") . "ようにしました");
                        break;
                    case "checkplayer":
                        $check = $this->getSetting()->get("check-inside");
                        if (!isset($args[2])) {
                            $sender->sendMessage(ReplenishResources::Prefix . $check ? "資源内にプレイヤーがいても補充します" : "資源内にプレイヤーがいると補充しません");
                            break;
                        }
                        if ($args[2] !== "on" and $args[2] !== "off") {
                            $sender->sendMessage(ReplenishResources::Prefix . "Usage: /reso setting checkplayer <on | off>");
                            break;
                        }
                        $this->getSetting()->set("check-inside", $args[2] === "on");
                        $this->getSetting()->save();
                        $sender->sendMessage(($args[2] === "on" ? "資源内にプレイヤーがいても補充する" : "資源内にプレイヤーがいると補充しない") . "ようにしました");
                        break;
                    case "reload":
                        $sender->sendMessage(ReplenishResources::Prefix . "設定ファイルを再読み込みしました");
                        $this->getSetting()->reload();
                        break;
                    default:
                        $sender->sendMessage(ReplenishResources::Prefix . "Usage: /reso setting <sneak | announce | checkplayer | reload>");
                        break;
                }
                break;
            default:
                $sender->sendMessage(ReplenishResources::Prefix . "Usage: /reso <pos1 | pos2 | add | del | change | cancel | auto | setting>");
                break;
        }
        return true;
    }

    public function onBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $pos = $block->getPosition();
        $name = $player->getName();
        if (isset($this->break[$name])) {
            $event->cancel();
            $type = $this->break[$name];
            switch ($type) {
                case 'pos1':
                case 'pos2':
                    $this->{$type}[$name] = $block->getPosition();
                    $player->sendMessage(ReplenishResources::Prefix . $type . "を設定しました (" . $pos->x . "," . $pos->y . "," . $pos->z . "," . $pos->world->getFolderName() . ")");
                    break;
            }
            unset($this->break[$name]);
            return;
        }
        if ((($block->getId() === 63 or $block->getId() === 68) and !$event->isCancelled()) and $this->api->existsResource($block->getPosition())) {
            if (!Server::getInstance()->isOp($player->getName())) {
                $player->sendMessage(ReplenishResources::Prefix . "§cこの看板は壊せません");
                $event->cancel();
                return;
            }
            $player->sendMessage(ReplenishResources::Prefix . "補充看板を壊しました");
            $this->api->removeResource($block->getPosition());
        }
    }

    public function onTouch(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $name = $player->getName();
        if ($block->getId() !== 63 and $block->getId() !== 68) return;
        if (isset($this->tap[$name])) {
            $event->cancel();
            switch ($this->tap[$name]["type"]) {
                case 'add':
                    $ids = array_map(function ($id2) {
                        $ids2 = explode(":", $id2);
                        return ["id" => $ids2[0], "damage" => $ids2[1] ?? 0, "per" => $ids2[2] ?? 100];
                    }, explode(",", $this->tap[$name]["id"]));
                    $this->api->addResource($block->getPosition(), $this->pos1[$name], $this->pos2[$name], $ids);
                    $player->sendMessage(ReplenishResources::Prefix . "追加しました");
                    break;
                case 'change':
                    if (!$this->api->existsResource($block->getPosition())) {
                        $player->sendMessage(ReplenishResources::Prefix . "その場所にはまだ追加されていません");
                        return;
                    }
                    $ids = array_map(function ($id2) {
                        $ids2 = explode(":", $id2);
                        return ["id" => $ids2[0], "damage" => $ids2[1] ?? 0, "per" => $ids2[2] ?? 100];
                    }, explode(",", $this->tap[$name]["id"]));
                    $this->api->updateResource($block->getPosition(), "id", $ids);
                    $player->sendMessage(ReplenishResources::Prefix . "変更しました");
                    break;
                case 'del':
                    if ($this->api->removeResource($block->getPosition())) {
                        $player->sendMessage(ReplenishResources::Prefix . "削除しました");
                    } else {
                        $player->sendMessage(ReplenishResources::Prefix . "その場所には登録されていません");
                    }
                    break;
                case 'auto_add':
                    if (!$this->api->existsResource($block->getPosition())) {
                        $player->sendMessage(ReplenishResources::Prefix . "それは補充看板ではありません");
                        return;
                    }
                    if (!$this->api->addAutoReplenishResource($block->getPosition())) {
                        $player->sendMessage(ReplenishResources::Prefix . "すでに追加されています");
                        return;
                    }
                    $player->sendMessage(ReplenishResources::Prefix . "追加しました");
                    if (!$this->setting->get("enable-auto-replenish")) $player->sendMessage(ReplenishResources::Prefix . "§e自動補充がオフになっています。/reso settingでオンにしてください");
                    break;
                case 'auto_del':
                    if (!$this->api->removeAutoReplenishResource($block->getPosition())) {
                        $player->sendMessage(ReplenishResources::Prefix . "まだ追加されていません");
                        return;
                    }
                    $player->sendMessage(ReplenishResources::Prefix . "削除しました");
                    break;
            }
            unset($this->tap[$name]);
            return;
        }
        if (!$this->api->existsResource($block->getPosition())) return;
        $place = $block->getPosition()->getX() . "," . $block->getPosition()->getY() . "," . $block->getPosition()->getZ() . "," . $block->getPosition()->getWorld()->getFolderName();
        if ($this->setting->get("sneak", false) and !$player->isSneaking()) {
            $player->sendMessage(ReplenishResources::Prefix . "スニークしながらタップすると補充します");
            return;
        }
        if ($this->setting->get("enable-wait", false) and (float)$this->setting->get("wait") > 0) {
            $time = $this->checkTime($player->getName(), $place);
            if ($time !== true) {
                $player->sendMessage(ReplenishResources::Prefix . $this->setting->get("wait") . "秒以内に使用しています。あと" . round($time, 1) . "秒お待ちください");
                return;
            }
        }
        $resource = $this->api->getResource($block->getPosition());
        if ($this->setting->get("check-inside", false)) {
            $players = $player->getWorld()->getPlayers();
            $inside = false;
            foreach ($players as $p) {
                $pp = $p->getPosition();
                if ($resource["level"] === $p->getWorld()->getFolderName() and $resource["startx"] <= floor($pp->x) and floor($pp->x) <= $resource["endx"] and $resource["starty"] <= floor($pp->y) and floor($pp->y) <= $resource["endy"] and $resource["startz"] <= floor($pp->z) and floor($pp->z) <= $resource["endz"]) {
                    $p->sendTip(ReplenishResources::Prefix . "§e" . $name . "があなたのいる資源を補充しようとしています");
                    $inside = true;
                }
            }
            if ($inside) {
                $player->sendMessage(ReplenishResources::Prefix . "資源内にプレイヤーがいるため補充できません");
                return;
            }
        }
        $allow = (int)$this->setting->get("count");
        if ($this->setting->get("enable-count", false) and $allow >= 0) {
            $count = $this->countBlocks($resource);
            if ($count > $allow) {
                $player->sendMessage(ReplenishResources::Prefix . "まだブロックが残っています (" . ($count - $allow) . ")");
                return;
            }
        }
        $resourceBlock = $this->api->getResource($block->getPosition());
        if ($resourceBlock === null) return;
        $placeBlock = null;
        foreach ($resourceBlock["id"] as $id) {
            $placeBlock = BlockFactory::getInstance()->get((int)$id["id"], (int)$id["damage"]);
        }
        if (is_null($placeBlock)) return;
        if ($this->setting->get("announcement")) $this->getServer()->broadcastMessage(ReplenishResources::Prefix . $name . "さんが{$block->getPosition()->getWorld()->getFolderName()}ワールドの{$placeBlock->getName()}資源({$this->countBlockAll($resource)}ブロック)の補充を実行しました");
        $this->api->replenish($block->getPosition());
    }

    public function checkTime(string $name, string $type) {
        if (!isset($this->time[$name][$type])) {
            $this->time[$name][$type] = microtime(true);
            return true;
        }
        $time = microtime(true) - $this->time[$name][$type];
        if ($time <= (float)$this->setting->get("wait")) {
            return (float)$this->setting->get("wait") - $time;
        }
        $this->time[$name][$type] = microtime(true);
        return true;
    }

    public function countBlocks(array $data): int {
        $sx = $data["startx"];
        $sy = $data["starty"];
        $sz = $data["startz"];
        $ex = $data["endx"];
        $ey = $data["endy"];
        $ez = $data["endz"];
        $level = $this->getServer()->getWorldManager()->getWorldByName($data["level"]);
        if ($level === null) return 0;
        $count = 0;
        for ($x = $sx; $x <= $ex; $x++) {
            for ($y = $sy; $y <= $ey; $y++) {
                for ($z = $sz; $z <= $ez; $z++) {
                    $block = $level->getBlock(new Vector3($x, $y, $z));
                    if ($block->getId() !== 0) $count++;
                }
            }
        }
        return $count;
    }

    public function countBlockAll(array $data): int {
        $sx = $data["startx"];
        $sy = $data["starty"];
        $sz = $data["startz"];
        $ex = $data["endx"];
        $ey = $data["endy"];
        $ez = $data["endz"];
        $level = $this->getServer()->getWorldManager()->getWorldByName($data["level"]);
        if ($level === null) return 0;
        $count = 0;
        for ($x = $sx; $x <= $ex; $x++) {
            for ($y = $sy; $y <= $ey; $y++) {
                for ($z = $sz; $z <= $ez; $z++) {
                    $count++;
                }
            }
        }
        return $count;
    }

    public static function getInstance(): self {
        return self::$instance;
    }
}