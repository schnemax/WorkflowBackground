<?php

namespace App;

final class Config
{
  public string $tz;
  public string $webhookSecret;
  public string $paperlessUrl;
  public string $paperlessToken;
  public string $mysqlDsn;
  public string $mysqlUser;
  public string $mysqlPass;
  public string $workerInternalToken;
  public string $typeMapFile;
  public string $fieldMapFile;
  public string $doctypesCacheFile;
  public int    $doctypesTtl;
  public string $workflowUrl;

  public function __construct()
  {
    // <<< lädt /etc/paperless-worker/workflow.env auch in der CLI
    Env::loadFromFile();

    $this->tz               = getenv('TZ') ?: 'Europe/Berlin';
    $this->webhookSecret    = getenv('APP_WEBHOOK_SECRET') ?: '';

    // WORKFLOW_URL
    $this->workflowUrl = rtrim(getenv('WORKFLOW_URL'));

    // PAPERLESS_URL (poller kommuniziert nur intern mit Paperless, nicht mit dem Frontend  )
    $this->paperlessUrl     = rtrim(getenv('API_TO_PAPERLESS_URL') ?: 'http://127.0.0.1:8010',
      '/'
    );
    if ($this->paperlessUrl === '' || !parse_url($this->paperlessUrl, PHP_URL_HOST)) {
      throw new \RuntimeException('Misconfigured: PAPERLESS_URL (oder PAPERLESS_BASE_URL) fehlt/ungültig');
    }

    // nur noch PAPERLESS_TOKEN (keine Duplikate)
    $this->paperlessToken   = trim(getenv('PAPERLESS_TOKEN') ?: '');
    if ($this->paperlessToken === '') {
      throw new \RuntimeException('Misconfigured: PAPERLESS_TOKEN fehlt');
    }

    $this->mysqlDsn         = getenv('MYSQL_DSN')  ?: '';
    $this->mysqlUser        = getenv('MYSQL_USER') ?: '';
    $this->mysqlPass        = getenv('MYSQL_PASS') ?: '';

    // interner Token für X-Internal-Auth
    $this->workerInternalToken = trim(getenv('WORKER_INTERNAL_TOKEN') ?: '');
    if ($this->workerInternalToken === '') {
      throw new \RuntimeException('Misconfigured: WORKER_INTERNAL_TOKEN fehlt');
    }

    $root = dirname(__DIR__);
    $this->typeMapFile   = getenv('TYPE_MAP_FILE')  ?: $root . '/config/type-map.json';
    $this->fieldMapFile  = getenv('FIELD_MAP_FILE') ?: $root . '/config/field-map.json';
    $this->doctypesCacheFile = getenv('DOCTYPES_CACHE_FILE') ?: sys_get_temp_dir() . '/worker_doctypes.cache.json';
    $this->doctypesTtl       = (int)(getenv('DOCTYPES_TTL') ?: 3600);
  }
}
