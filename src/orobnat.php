<?php
/**
 * Class orobnat
 *
 * Data are fetched from https://orobnat.sante.gouv.fr once a day by request.
 * This is an unofficial API to get this data over JSON.
 * Script is available here : https://github.com/jim005/orobnat-api-php .
 *
 */

class orobnat
{


    protected $post = array();

    private $reseauUrl;
    private $departementUrl;
    private $communeDepartementUrl;
    private $idRegionUrl;


    /**
     * orobnat constructor.
     * @param string $idRegion
     * @param string $departement
     * @param string $communeDepartement
     * @param string $reseau
     */
    public function __construct(string $idRegion, string $departement, string $communeDepartement, string $reseau)
    {
        // Avoir le format pour le reseau : 072003592_072
        $prefix = substr($reseau, 0, 3);
        $reseau = $reseau . "_" . $prefix;

        //Mise en variable globale pour la classe
        $this->idRegionUrl=$idRegion;
        $this->departementUrl=$departement;
        $this->communeDepartementUrl=$communeDepartement;
        $this->reseauUrl=$reseau;

        $this->post = array(
            'idRegion' => filter_var($idRegion, FILTER_SANITIZE_STRING),
            'departement' => filter_var($departement, FILTER_SANITIZE_STRING),
            'communeDepartement' => filter_var($communeDepartement, FILTER_SANITIZE_STRING),
            'reseau' => filter_var($reseau, FILTER_SANITIZE_STRING),
        );
	
    }

    /**
     * Get Cookies from a first visit. We need it to get result after
     *
     * @return array|null
     */
    private function _requestCookies()
    {

        $cookies = null;

        $ch = curl_init();
        $Url="https://orobnat.sante.gouv.fr/orobnat/afficherPage.do?methode=menu&usd=AEP&idRegion=".$this->idRegionUrl."&dpt=".$this->departementUrl."&comDpt=".$this->communeDepartementUrl;
        curl_setopt($ch, CURLOPT_URL, $Url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);


        if (preg_match('/^Set-Cookie:\s*([^;]*)/mi', $result, $cookie) == 1) {
            $cookies[] = $cookie;

        }


        return $cookies;
    }

    /**
     * Get data from Orabnat website. Return HTML's page content
     *
     * @return bool|string
     */
    private function _requestData()
    {

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://orobnat.sante.gouv.fr/orobnat/rechercherResultatQualite.do');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge($this->post, array(
                    'methode' => 'rechercher',
                    'usd' => 'AEP',
                    'posPLV' => '0'
                )
            )
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $headers = array();
        $cookies = $this->_requestCookies();
        $headers[] = "Cookie: " . $cookies[0][1] . "; " . $cookies[1][1];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        return $result;
    }

    /**
     * Main function to get data
     *
     * @return array
     */
    public function getData()
    {

        return $this->_parseAndCleanData($this->_requestData());

    }

    /**
     * Parse HTML data then return PHP Array with result.
     *
     * @param $result
     * @return array
     */
    private function _parseAndCleanData($result)
    {

        $data = array();

        $data["intro"]['sources'] = "Data are fetched from https://orobnat.sante.gouv.fr once a day by request. This is an unofficial API to get this data over JSON. Script is available here : https://github.com/jim005/orobnat-api-php . Pull Request are welcome ^^";
        $data["intro"]['cached_date'] = time();

        /*** a new dom object ***/
        $dom = new DOMdocument();

        $dom->loadHTML($result);
        $dom->preserveWhiteSpace = false;


        // Recherche de la liste des reseaux à partir de reseauUrl (select)

        $listeSelect = $dom->getElementsByTagName('select');
        $reseauTrouve = false; // Déclaration de la variable avant la boucle

        foreach ($listeSelect as $select) {
            $selectName = $select->getAttribute('name');

            if ($selectName == "departement") continue;
            if ($selectName == "communeDepartement") continue;

            if ($selectName == "reseau") {
                $options = $select->getElementsByTagName('option');

                foreach ($options as $option) {
                    $reseauSite = $option->getAttribute('value');

                    if ($reseauSite=='$this->reseauUrl') {
                        $reseauTrouve = true;
                        break;
                    }
                }
            }
        }

        if($reseauTrouve) {

            // Recherche des données pour l'analyse (table)


            $tables = $dom->getElementsByTagName('table');

            foreach ($tables as $table) {

                $SectionName = $table->parentNode->getElementsByTagName('h3')->item(0)->nodeValue;
                $SectionKey = $this->transliteration_clean_filename($SectionName);
                $data[$SectionKey]['section_nom'] = $SectionName;
                $rows = $table->getElementsByTagName('tr');

                foreach ($rows as $row) {

                    $cols = $row->childNodes;

                    // Remove useless row :: from property
                    if (empty($cols->item(1)->nodeValue) || $cols->item(1)->nodeValue == "" || $cols->item(1)->nodeValue == "Paramètre") {
                        continue;
                    }
                    // Remove useless row :: from value
                    if ($cols->item(3)->nodeValue == "* Analyse  réalisée sur le terrain") {
                        continue;
                    }

                    $keyRow = $this->transliteration_clean_filename($cols->item(1)->nodeValue);

                    $data[$SectionKey][$keyRow]['labelOriginal'] = $cols->item(1)->nodeValue;
                    $data[$SectionKey][$keyRow]['valueOriginal'] = trim(preg_replace("/[\r\t\n]+/", " ", $cols->item(3)->nodeValue));

                    if (!is_null($this->getValueOnly($cols->item(3)->nodeValue))) {
                        $data[$SectionKey][$keyRow]['valueOnly'] = $this->getValueOnly($cols->item(3)->nodeValue);
                        $data[$SectionKey][$keyRow]['valueUnity'] = trim(preg_replace("/[\r\t\n]+/", " ", $cols->item(3)->nodeValue));
                    }

                }

            }
        } else { $data="{}"; }

        return $data;

    }

    /**
     * Extract from raw value an useful value to analyze, store and compare
     *
     * @param $value
     * @return bool|float|int|null
     */
    private function getValueOnly($value)
    {

        $value = trim(preg_replace("/[\r\t\n]+/", "", $value));
        $value = strtr($value, array("unité pH" => "", "mg(Cl2)/L" => "", "NFU" => "", "mg/L" => "", "mg(C)/L" => "", "," => ".", "µS/cm" => "", "mg(Pt)/L" => "", "n/(100mL)" => "", "n/mL" => "", "°C" => "", " " => "", "<" => "", ">" => ""));

        $output = null;

        if (is_numeric($value)) {
            $output = (float)$value;
        } else if ($value === "oui") {
            $output = true;
        } else if ($value === "non") {
            $output = false;
        } else if (preg_match('/^\d{2}\/\d{2}\/\d{4}/m', $value)) {

            preg_match_all('/^(\d{2}\/\d{2}\/\d{4}).*(\d{2}[h]\d{2})/m', $value, $matches, PREG_SET_ORDER, 0);
            $date_cleanned = $matches[0][1] . " " . $matches[0][2];
            $output = (int)DateTime::createFromFormat('d/m/Y H\hi', $date_cleanned)->format("U");

        } else {
            $output = null;
        }

        return $output;

    }

    /**
     * Get clean name to use as filename or array key
     *
     * @param $filename
     * @return string|string[]|null
     */
    private function transliteration_clean_filename($filename)
    {


        // Replace accents
        $unwanted_array = array('Š' => 'S', 'š' => 's', 'Ž' => 'Z', 'ž' => 'z', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E',
            'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Ù' => 'U',
            'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'B', 'ß' => 'Ss', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'a', 'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ð' => 'o', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ý' => 'y', 'þ' => 'b', 'ÿ' => 'y');
        $filename = strtr($filename, $unwanted_array);


        // Replace whitespace.
        $filename = str_replace(' ', '_', $filename);
        // Remove remaining unsafe characters.
        $filename = preg_replace('![^0-9A-Za-z_.-]!', '', $filename);
        // Force lowercase to prevent issues on case-insensitive file systems.
        $filename = strtolower($filename);


        return $filename;
    }

    /**
     * Store file in specific folder. Useful for small caching functionality
     *
     * @param $dir
     * @param $contents
     * @return bool
     */
    public function file_force_contents($dir, $contents)
    {
        $parts = explode('/', $dir);
        $file = array_pop($parts);
        $dir = '';
        foreach ($parts as $part)
            if (!is_dir($dir .= "/$part")) mkdir($dir);
        file_put_contents("$dir/$file", $contents);

        return true;
    }

}
