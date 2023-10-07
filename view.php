<?php
/** Visualiser avec Leaflet les couches WMS, WMTS et TMS de la géoplateforme */
$htmlHeader = "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>gpf/view</title></head><body>\n";

/** classe abstraite d'un serveur GPF */
readonly abstract class GpfServer {
  /** Liste des protocoles avec les classes Php pour les gérer */
  const PROTOCOLS = [
    'WMTS'=> [
      'serverClass'=> 'WmtsServer',
      'layerClass'=> 'WmtsLayer',
    ],
    'WMS'=> [
      'serverClass'=> 'WmsServer',
      'layerClass'=> 'WmsLayer',
    ],
    'TMS'=> [
      'serverClass'=> 'TmsServer',
      'layerClass'=> 'TmsLayer',
    ],
  ];
  /** Liste des serveurs de la GPF avec leur URL et la sous-classe sachant les traiter */
  const SERVERS = [
    'wmts'=> [
      'title'=> "WMTS",
      'protocol'=> 'WMTS',
      'url'=> 'https://data.geopf.fr/wmts',
    ],
    'wmts-beta'=> [
      'title'=> "WMTS béta",
      'protocol'=> 'WMTS',
      'url'=> 'https://data.geopf.fr/beta/wmts',
    ],
    'wms-r'=> [
      'title'=> "WMS-R",
      'protocol'=> 'WMS',
      'url'=> 'https://data.geopf.fr/wms-r/wms',
    ],
    'wms-v'=> [
      'title'=> "WMS-V",
      'protocol'=> 'WMS',
      'url'=> 'https://data.geopf.fr/wms-v/ows',
    ],
    'pWms'=> [
      'title'=> "pWms",
      'protocol'=> 'WMS',
      'url'=> '{geoapiUrl}/gpf/pwms.php',
    ],
    'tms'=> [
      'title'=> "TMS",
      'protocol'=> 'TMS',
      'url'=> 'https://data.geopf.fr/tms/1.0.0',
    ],
  ];
  /** l'id du serveur */
  public string $server;
  /* les capacités comme SimpleXMLElement */
  public SimpleXMLElement $cap;
  
  /** appelle la création sur la sous-clase adéquate pour le serveur dont l'id est passé en en paramètre */
  static function create(string $server): self {
    $protocol = self::SERVERS[$server]['protocol'];
    return new (self::PROTOCOLS[$protocol]['serverClass']) ($server);
  }
  
  /** crée un serveur, méthode héritée par les sous-classes, prend en paramètre l'id du serveur */
  function __construct(string $server) {
    $this->server = $server;
    if (is_file(__DIR__."/$server.xml") && (time() - filemtime(__DIR__."/$server.xml") < 12*60*60))
      $cap = file_get_contents(__DIR__."/$server.xml");
    else {
      $getcapUrl = $this->getcapUrl();
      if (($cap = file_get_contents($getcapUrl)) === false)
        die("erreur de lecture de $getcapUrl");
      file_put_contents(__DIR__."/$server.xml", $cap);
    }
    $cap = $this->processCap($cap);
    $this->cap = new SimpleXmlElement($cap);
  }

  static function serverUrl(string $server): string {
    $url = self::SERVERS[$server]['url'];
    $geoapiUrl = ($_SERVER['HTTP_HOST']=='localhost') ? 'http://localhost/geoapi' : 'https://geoapi.fr';
    return str_replace('{geoapiUrl}', $geoapiUrl, $url);
  }
  
  /** génère l'URL pour connaître les capacités du serveur */
  abstract function getcapUrl(): string;
  
  /** pré-traitement des capacités en XML avant construction du SimpleXMLElement pour simplifier l'accès.
   * Par défaut pas de prétraitement */
  function processCap(string $cap): string { return $cap; }
  
  /** Retourne la liste des couches sous la forme [{name} => WxsLayer].
   * @return array<string,WxsLayer> */
  function layers(): array {
    $layers = [];
    if ($this->layersAsXml())
    foreach ($this->layersAsXml() as $layer) {
      $protocol = self::SERVERS[$this->server]['protocol'];
      $layer = new (self::PROTOCOLS[$protocol]['layerClass'])($this->server, $layer);
      $layers[$layer->name()] = $layer;
    }
    return $layers;
  }
  
  /** Retourne la liste des couches comme SimpleXMLElement. */
  abstract function layersAsXml(): SimpleXMLElement;
  
  /** Retourne la couche ayant le nom indiqué */
  function layer(string $name): ?WxsLayer {
    foreach ($this->layersAsXml() as $layer) {
      $protocol = self::SERVERS[$this->server]['protocol'];
      $layer = new (self::PROTOCOLS[$protocol]['layerClass'])($this->server, $layer);
      if ($layer->name() == $name)
        return $layer;
    }
    return null;
  }
};  

/** classe abstraite d'une couche d'un serveur */
readonly abstract class WxsLayer {
  /** l'id du serveur contenant la couche */
  public string $server;
  /** le fragment des capacités de la couche comme SimpleXMLElement */
  public SimpleXMLElement $cap;

  /** définition d'une couche */
  function __construct(string $server, SimpleXmlElement $cap) { $this->server = $server; $this->cap = $cap; }

  /** Nom de la couche */
  abstract function name(): string;
  
  /** Titre de la couche */
  abstract function title(): string;
  
  /** liste des styles de la couche sous la forme [{name} => WxsStyle].
   * @return array<string,WxsStyle>
   */
  abstract function styles(): array;

  /** affiche une doc */
  function doc(string $htmlHeader): never { echo $htmlHeader,'<pre>'; print_r($this); die(); }
  
  /** génération du code JS de définition de la couche dans Leaflet */
  abstract function leafletCode(): string;
};

/** classe abstraite d'un style d'une couche */
readonly abstract class WxsStyle {
  /** le fragment des capacités du style comme SimpleXMLElement */
  public SimpleXMLElement $cap;

  /** définition d'un style */
  function __construct(SimpleXmlElement $cap) { $this->cap = $cap; }

  /** le titre du style */
  abstract function title(): string;
};

/** Style pour une couche WMS */
readonly class WmsStyle extends WxsStyle {
  function title(): string { return $this->cap->Title; }
};

/** Couche d'un serveur WMS */
readonly class WmsLayer extends WxsLayer {  
  function name(): string { return $this->cap->Name; }

  function title(): string { return $this->cap->Title; }
  
  function styles(): array {
    //echo "<pre>"; print_r($this->cap);
    $styles = [];
    foreach ($this->cap->Style as $style) {
      $styles[(string)$style->Name] = new WmsStyle($style);
    }
    return $styles;
  }
  
  function leafletCode(): string {
    $title = $this->title();
    $name = $this->name();
    $url = GpfServer::serverUrl($this->server);
    return <<<EOT
      "$title" : new L.tileLayer.wms('$url',
      { "version":"1.3.0","layers":"$name","format":"image/png","transparent":true,
        "detectRetina":detectRetina, "attribution":attrIGN }
      )
EOT;
  }
};

/* Serveur WMS */
readonly class WmsServer extends GpfServer {
  function getcapUrl(): string {
    return self::serverUrl($this->server).'?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetCapabilities';
  }
  
  function processCap(string $cap): string { return $cap; }
  
  function layersAsXml(): SimpleXMLElement { return $this->cap->Capability->Layer->Layer; }
};

/** Style pour une couche WMTS */
readonly class WmtsStyle extends WxsStyle {
  function title(): string { return $this->cap->ows_Title; }
};

/* Couche WMTS */
readonly class WmtsLayer extends WxsLayer {
  function name(): string { return $this->cap->ows_Identifier; }
  
  function title(): string { return $this->cap->ows_Title; }
  
  function styles(): array {
    $styles = [];
    foreach ($this->cap->Style as $style) {
      $styles[(string)$style->ows_Identifier] = new WmtsStyle($style);
    }
    return $styles;
  }
  
  private function tileMatrixSet(): string { return $this->cap->TileMatrixSetLink->TileMatrixSet; }
  
  private function minZoom(): int {
    $zoom = 9999;
    foreach ($this->cap->TileMatrixSetLink->TileMatrixSetLimits->TileMatrixLimits as $TileMatrixLimit) {
      //echo 'TileMatrix=',$TileMatrixLimit->TileMatrix,"\n";
      if ($TileMatrixLimit->TileMatrix < $zoom)
        $zoom = (int)$TileMatrixLimit->TileMatrix;
    }
    //echo "zoom=$zoom\n";
    return $zoom;
  }
  
  private function maxZoom(): int {
    $zoom = -1;
    foreach ($this->cap->TileMatrixSetLink->TileMatrixSetLimits->TileMatrixLimits as $TileMatrixLimit) {
      //echo 'TileMatrix=',$TileMatrixLimit->TileMatrix,"\n";
      if ($TileMatrixLimit->TileMatrix > $zoom)
        $zoom = (int)$TileMatrixLimit->TileMatrix;
    }
    //echo "zoom=$zoom\n";
    return $zoom;
  }
  
  /** code JS de Leaflet pour défini la couche */
  function leafletCode(string $styleName=null): string {
    $url = GpfServer::serverUrl($this->server);
    $title = $this->title();
    $name = $this->name();
    if (!$styleName) {
      $styleNames = array_keys($this->styles());
      //echo "styleNames="; print_r($styleNames);
      if (count($styleNames) == 1)
         $styleName = urlencode($styleNames[0]);
      else
        throw new Exception("Plusieurs styles");
    }
    $format = $this->cap->Format;
    $minZoom = $this->minZoom();
    $maxZoom = $this->maxZoom();
    $url = GpfServer::serverUrl($this->server)
      .'?service=WMTS&version=1.0.0&request=GetTile&tilematrixSet=PM&height=256&width=256'
      .'&tilematrix={z}&tilecol={x}&tilerow={y}'
      ."&layer=$name&format=$format&style=$styleName";
    //echo "url=$url<br>\n";
    return <<<EOT
      "$title" : new L.tileLayer(
        '$url',
        {"format":"$format","minZoom":$minZoom,"maxZoom":$maxZoom,"detectRetina":false,"attribution":attrIGN}
      )\n
EOT;
  }
};

/* Serveur WMTS */
readonly class WmtsServer extends GpfServer {
  function getcapUrl(): string {
    return self::serverUrl($this->server).'?SERVICE=WMTS&VERSION=1.0.0&REQUEST=GetCapabilities';
  }
  
  function processCap(string $cap): string {
    return str_replace('ows:','ows_',$cap);
  }
  
  function layersAsXml(): SimpleXMLElement { return $this->cap->Contents->Layer; }
};

/** Couche d'un serveur TMS */
readonly class TmsLayer extends WxsLayer {
  function name(): string {
    //echo "<pre>cap="; print_r($this->cap);
    preg_match('!/([^/]+)$!', $this->cap['href'], $matches);
    return $matches[1];
  }
  
  function title(): string { return $this->cap['title']; }
  
  function styles(): array { return [''=>null]; }
  
  /* récupère les capacités de la couche et les affiche comme XML */
  function doc(string $htmlHeader): never {
    $url = TmsServer::serverUrl($this->server).'/'.$this->name();
    //echo "url=$url<br>\n";
    $cap = file_get_contents($url);
    header('Content-Type: text/xml');
    die($cap);
  }
  
  function leafletCode(string $styleName=null): string {
    $title = $this->title();
    $name = $this->name();
    $ext = $this->cap['extension'];
    //echo "ext=$ext<br>\n";
    $url = TmsServer::serverUrl($this->server)."/$name/{z}/{x}/{y}.$ext";
    //echo "url=$url<br>\n";
    return <<<EOT
      "$title" : new L.tileLayer(
        '$url',
        {"detectRetina":false,"attribution":attrIGN}
      )\n
EOT;
  }
}

/** Serveur TMS */
readonly class TmsServer extends GpfServer {
  function getcapUrl(): string { return self::serverUrl($this->server); }
  
  function layersAsXml(): SimpleXMLElement { return $this->cap->TileMaps->TileMap; }
};

switch ($_GET['action'] ?? null) {
  case null: {
    echo $htmlHeader,
      "<a href='?action=cap'>afficher la liste des serveurs et leur contenu</a><br>\n";
    die();
  }
  case 'cap': {
    if (!isset($_GET['server'])) { // si serveur non défini alors affiche la liste des serveurs
      echo $htmlHeader;
      foreach (GpfServer::SERVERS as $id => $server)
        echo "<a href='?action=cap&server=$id'>$server[title]</a>",
              " (<a href='?action=doc&server=$id'>doc</a>, <a href='?action=clear&server=$id'>clear</a>)<br>\n";
      die();
    }
    // affiche la liste des couches du serveur
    $server = GpfServer::create($_GET['server']);
    echo $htmlHeader,"Layers($_GET[server]):<ul>\n";
    foreach ($server->layers() as $layer) {
      $layerName = $layer->name();
      $styles = $layer->styles();
      //echo "<pre>styles="; print_r($styles);
      if (count($styles)==1)
        echo "<li><a href='?action=view&server=$_GET[server]&layer=$layerName'>",
              $layer->title()," (",$layer->name(),")</a>",
              " (<a href='?action=doc&server=$_GET[server]&layer=$layerName'>doc</a>)</li>\n";
      else {
        echo "<li>",$layer->title(),
             " (<a href='?action=doc&server=$_GET[server]&layer=$layerName'>doc</a>)",
             "<ul>\n";
        foreach ($styles as $styleName => $style)
          echo "<li><a href='?action=view&server=$_GET[server]&layer=",$layer->name(),"&style=$styleName'>",
                $style->title(),"</a></li>\n";
        echo "</ul>\n";
      }
    }
    die();
  }
  case 'clear': {
    if (is_file(__DIR__."/$_GET[server].xml")) {
      unlink(__DIR__."/$_GET[server].xml");
      die("ok");
    }
    die("le fichier n'existe pas");
  }
  case 'doc': {
    $server = GpfServer::create($_GET['server']);
    if (!isset($_GET['layer'])) {
      echo "$htmlHeader\n";
      echo "<pre>"; print_r($server);
      die();
    }
    $layer = $server->layer($_GET['layer']);
    $layer->doc($htmlHeader);
    die();
  }
  case 'view': {
    $geoapiUrl = ($_SERVER['HTTP_HOST']=='localhost') ? 'http://localhost/geoapi' : 'https://geoapi.fr';
    $server = GpfServer::create($_GET['server']);
    $layer = $server->layer($_GET['layer']);
    //print_r($layer);
    $llayer = $layer->leafletCode($_GET['style'] ?? null);
    $laddOverlays = 'map.addLayer(overlays["'.$layer->title().'"]);';
    break;
  }
  default: die("action $_GET[action] inconnue");
}
?>

<!DOCTYPE html>
  <head>
    <title>carte GPF</title>
    <meta charset="UTF-8">
<!-- meta nécessaire pour le mobile -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
<!-- styles nécessaires pour le mobile -->
    <link rel="stylesheet" href="https://visu.gexplor.fr/viewer.css">
<!-- styles et src de Leaflet -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9/dist/leaflet.js"></script>
  </head>
  <body>
    <div id="map" style="height: 100%; width: 100%"></div>
    <script>
var map = L.map('map').setView([48, 3], 8); // view pour la zone
L.control.scale({position:'bottomleft', metric:true, imperial:false}).addTo(map);

var wmtsurl = 'https://data.geopf.fr/wmts?'
            + 'service=WMTS&version=1.0.0&request=GetTile&tilematrixSet=PM&height=256&width=256&'
            + 'tilematrix={z}&tilecol={x}&tilerow={y}';
var detectRetina = false;
var geoapiUrl = <?php echo "'$geoapiUrl';\n"; ?>
var attrIGN = "&copy; <a href='http://www.ign.fr'>IGN</a>";
var attrINPN = "&copy; <a href='http://inpn.mnhn.fr'>INPN</a>";

var baseLayers = {
  "Plan IGN" : new L.TileLayer(
    'https://data.geopf.fr/beta/wmts?service=WMTS&version=1.0.0&request=GetTile&tilematrixSet=PM&height=256&width=256'
     +'&tilematrix={z}&tilecol={x}&tilerow={y}&layer=PLAN-IGN_PNG&format=image/png&style=normal',
    {"format":"image/png","minZoom":0,"maxZoom":19,"detectRetina":false,"attribution":attrIGN}
  ),
  "Cartes IGN": new L.tileLayer.wms(geoapiUrl+'/gpf/pwms.php',
    { "version":"1.3.0","layers":"cartesIGN","format":"image/png","transparent":true,
      "detectRetina":detectRetina, "attribution":attrIGN }
  ),
  "Ortho 20 cm" : new L.tileLayer(
    'https://data.geopf.fr/wmts?service=WMTS&version=1.0.0&request=GetTile&tilematrixSet=PM&height=256&width=256'
     +'&tilematrix={z}&tilecol={x}&tilerow={y}&layer=HR.ORTHOIMAGERY.ORTHOPHOTOS&format=image/jpeg&style=normal',
    {"format":"image/jpeg","minZoom":6,"maxZoom":19,"detectRetina":false,"attribution":attrIGN}
  ),
  "OSM" : new L.TileLayer(
      'http://{s}.tile.osm.org/{z}/{x}/{y}.png',
      { "format":"image/jpeg","minZoom":0,"maxZoom":19,"detectRetina":detectRetina,
        attribution: '&copy; <a href="http://osm.org/copyright">OpenStreetMap</a> contributors'}
  )
};
      
map.addLayer(baseLayers["Plan IGN"]);

var overlays = {
<?php echo $llayer; ?>
};

<?php echo $laddOverlays; ?>

<!-- ajout de l outil de sélection de couche -->
L.control.layers(baseLayers, overlays).addTo(map);

    </script>
  </body>
</html>
