<?php
/** Implémentation d'un serveur WMS proxy */
require_once __DIR__.'/lib/sexcept.inc.php';
require_once __DIR__.'/lib/wmsserver.inc.php';

/** classe regroupant l'intelligence autour du tuilage et des niveaux de zoom */
class Zoom {
  /**
   * Size0 est la circumférence de la Terre en mètres utilisée dans la projection WebMercator
   *
   * correspond à 2 * PI * a où a = 6 378 137.0 est le demi-axe majeur de l'ellipsoide WGS 84
   * Size0 est le côté du carré contenant les points en coordonnées WebMercator */
  const Size0 = 20037508.3427892476320267 * 2;
};

/** classe implémentant le serveur WMS proxy.
 * La classe ProxyWms hérite de la classe AbstractWmsServer qui gère le protocole WMS.
 * Le script appelle AbstractWmsServer::process() qui appelle les méthodes WmsShomGt::getCapabilities() ou WmsShomGt::getMap()
*/
class ProxyWms extends AbstractWmsServer {
  /** méthode GetCapabilities du serveur ProxyWms */
  function getCapabilities(string $version=''): never {
    header('Content-Type: text/xml');
    $request_scheme = $_SERVER['REQUEST_SCHEME'] ?? $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http';
    die(str_replace(
        '{OnlineResource}',
        "$request_scheme://$_SERVER[SERVER_NAME]$_SERVER[PHP_SELF]?",
        file_get_contents(__DIR__.'/pwmscapabilities.xml')
    ));
  }
  
  /** méthode GetMap du serveur WMS Shomgt.
  * @param array<int, string> $lyrnames
  * @param array<int, string> $styles
  * @param array<int, string> $bbox
  */
  function getMap(string $version, array $lyrnames, array $styles, array $bbox, string $crs, int $width, int $height, string $format, string $transparent, string $bgcolor): never {
    switch ($lyrnames[0]) {
      case 'cartesIGN': {
        $scaleDen = round((intval($bbox[2]) - intval($bbox[0])) / ($width * 0.28 * 1e-3));
        $pxSze = (intval($bbox[2]) - intval($bbox[0])) / $width;
        $zoom = round(log(Zoom::Size0 / $pxSze / 256, 2));
        if ($scaleDen > 2_000_000)
          AbstractWmsServer::exception(400, "Erreur scaleDen=$scaleDen > 2_000_000, zoom=$zoom");
        elseif ($scaleDen > 500_000)
          $layer = 'SCAN1000_PYR-JPEG_WLD_WM';
        elseif ($scaleDen > 150_000)
          $layer = 'SCANREG_PYR-JPEG_WLD_WM'; // 1/250k
        elseif ($scaleDen > 100_000)
          $layer = 'SCANDEP_PYR-JPEG_FXX_WM'; // 1/150k
        elseif ($scaleDen > 50_000)
          $layer = 'SCAN100_PYR-JPEG_WLD_WM';
        elseif ($scaleDen < 2_000)
          AbstractWmsServer::exception(400, "Erreur scaleDen=$scaleDen < 2_000, zoom=$zoom");
        else
          $layer = 'SCAN25TOUR_PYR-JPEG_WLD_WM';
        parent::log("zoom=$zoom, scaleDen=$scaleDen, layer=$layer");
        $url = 'https://data.geopf.fr/wms-r/wms'
              ."?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&layers=$layer&STYLES="
              ."&CRS=$crs&BBOX=".implode(',', $bbox)
              ."&width=$width&height=$height"
              ."&format=$format&transparent=$transparent";
        if (($image = file_get_contents($url)) === false)
          AbstractWmsServer::exception(500, "Erreur de lecture de https://data.geopf.fr/wms-r/wms");
        switch ($format) {
          case 'image/png': {
            header('Content-type: image/png');
            break;
          }
          case 'image/jpeg': {
            header('Content-type: image/jpeg');
            break;
          }
          default: AbstractWmsServer::exception(500, "format $format non défini");
        }
        die ($image);
      }
      case 'debug': {
        
      }
    }
    die();
  }
};

//print_r($_GET); die();
if (!$_GET) {
  echo "<a href='?SERVICE=WMS&REQUEST=GetCapabilities'>GetCap</a><br>";
  $url = 'https://data.geopf.fr/wms-r/wms'; $layer = 'SCAN1000_PYR-JPEG_WLD_WM';
  $url = ''; $layer = 'cartesIGN';
  echo "<a href='$url?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&layers=$layer&STYLES=",
        "&CRS=EPSG:3857&BBOX=-626172,5948635,-313086,6261721",
        "&width=1024&height=1024",
        "&format=image/png&transparent=true",
        "'>GetMap</a><br>";
  echo "<a href='$url?SERVICE=WMS&VERSION=1.3.0&REQUEST=GetMap&layers=$layer&STYLES=",
        "&CRS=EPSG:3857&BBOX=-626172,5948635,-313086,6261721",
        "&width=512&height=512",
        "&format=image/png&transparent=true",
        "'>GetMap exception</a><br>";
  die();
}

try {
  $server = new ProxyWms;
  $server->process($_GET);
}
catch (SExcept $e) {
  switch($e->getSCode()) {
    default: {
      AbstractWmsServer::exception(500, "Erreur de traitement de la requête", '', $e->getMessage());
    }
  }
}
