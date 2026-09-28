<?php
declare(strict_types=1);

namespace StageCms;

use StageCms\Content\Pages;
use StageCms\Identity\Identity;
use StageCms\Infrastructure\Config;
use StageCms\Infrastructure\Database;
use StageCms\Infrastructure\Settings;
use StageCms\Media\Library;

final readonly class Cms
{
    public Database $db;
    public Identity $identity;
    public Pages $pages;
    public Library $media;
    public Settings $settings;

    public function __construct(public Config $config)
    {
        $this->db = new Database($config->data . '/cms.sqlite');
        $this->db->migrate();
        $this->identity = new Identity($this->db);
        $this->pages = new Pages($this->db);
        $this->media = new Library($this->db, $config->data . '/media');
        $this->settings = new Settings($this->db);
    }
}
