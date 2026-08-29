<?php

namespace App\Libraries;

use App\Models\Library\County;
use App\Models\Library\FireZone;
use App\Models\Library\MarineZone;
use App\Models\Library\OffshoreZone;
use App\Models\Library\State;
use App\Models\Library\TextProduct;
use App\Models\Library\VtecAction;
use App\Models\Library\VtecClass;
use App\Models\Library\VtecPhenomena;
use App\Models\Library\VtecProduct;
use App\Models\Library\VtecSignificance;
use App\Models\Library\Wfo;
use App\Models\Library\Zone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * Class AwipsProduct
 */
class AwipsProduct
{
    /**
     * @var string
     */
    private $fileName;

    /**
     * @var array
     */
    private $fileContents;

    /**
     * @var array
     */
    private $productInfo;

    /**
     * Constructor
     *
     * @return void
     */
    public function __construct(string $filename)
    {
        $this->fileName = $filename;
        $graphicsFlag = false;
        if (preg_match('/\.txt$/i', basename($filename))) {
            // Product is a test file
            $this->fileContents = $this->parseFileContents(file($filename));
        } elseif (preg_match('/\.(JPG|GIF|PNG)$/', basename($filename))) {
            // Product is a graphic file
            $this->fileContents = base64_encode(file_get_contents($filename));
            $graphicsFlag = true;
        } else {
            throw new \Exception('Invalid product file!');
        }
        $this->productInfo = $this->splitProductInfo($graphicsFlag);
    }

    /**
     * Returns the filename of the product
     *
     * @return string The product's filename
     */
    public function getFileName(): string
    {
        return $this->fileName;
    }

    /**
     * Returns the source of the product (nwws or emwin)
     *
     * @return string The product's source
     */
    public function getProductSource(): ?string
    {
        if (preg_match('/\/nwws\//', $this->fileName)) {
            return 'nwws';
        } elseif (preg_match('/emwin/', $this->fileName)) {
            return 'emwin';
        } else {
            throw new \Exception('Could not determine product source (filename: '.$this->fileName.')');

            return null;
        }
    }

    /**
     * Returns whether product is currently active or not
     *
     * @return bool TRUE is the product is active, FALSE otherwise
     */
    public function isActive(): bool
    {
        if ($this->productInfo['zoneClass'] === 'A') {
            return (time() - $this->getProductTime()) < (60 * 60 * 8) ? true : false;
        } elseif ($this->productInfo['zoneClass'] === 'B' || $this->productInfo['zoneClass'] === 'C') {
            if (isset($this->productInfo['zoneHeaderList'][0]) && isset($this->productInfo['zoneHeaderList'][0]['purgeTime'])) {
                return (time() - $this->productInfo['zoneHeaderList'][0]['purgeTime']) < (60 * 60 * 8) ? true : false;
            } else {
                return (time() - $this->getProductTime()) < (60 * 60 * 8) ? true : false;
            }
        } else {
            throw new \Exception('Invalid zone class!');
        }

        return false;
    }

    /**
     * Returns the product's WMO Header
     *
     * @return string The product's WMO Header
     */
    public function getWmoHeader(): string
    {
        return $this->productInfo['wmoHeader'];
    }

    /**
     * Returns AWIPS ID
     *
     * @return string The product's AWIPS ID
     */
    public function getAwipsId(): string
    {
        return $this->productInfo['awipsId'];
    }

    /**
     * Returns the product's zone class
     *
     * @return string The product's zone class (A, B, or C)
     */
    public function getZoneClass(): string
    {
        return $this->productInfo['zoneClass'];
    }

    /**
     * Returns the product's type
     *
     * @return string 3-digit abbreviation of the product's type
     */
    public function getProductType(): string
    {
        return ! empty($this->productInfo) ? $this->productInfo['productType'] : '???';
    }

    /**
     * Obtains a list of the product's 3-letter abbreviations
     *
     * @return array list of 3-digit abbreviations with their titles
     */
    public function getProductAbbrs(): array
    {
        $abbrs = [];
        if (! empty($this->productInfo)) {
            if ($this->getZoneClass() === 'A') {
                $fullName = 'n/a';
                switch ($this->getAwipsId()) {
                    case 'SWODY1':
                        $fullName = 'Day 1 Convective Outlook';
                        break;
                    case 'SWODY2':
                        $fullName = 'Day 2 Convective Outlook';
                        break;
                    case 'SWODY3':
                        $fullName = 'Day 3 Convective Outlook';
                        break;
                    case 'SWOD48':
                        $fullName = 'Day 4-8 Convective Outlook';
                        break;
                    case 'SWOMCD':
                        $fullName = 'Mesoscale Discussion';
                        break;
                    default:
                        $fullName = $this->getFullNameFromAbbr($this->getProductType());
                }
                $abbrs[$this->getProductType()] = $fullName;
            } elseif ($this->getZoneClass() === 'B' || $this->getZoneClass() === 'C') {
                // Loop through zone header list and look for VTECs
                $zoneHeaderList = $this->getZoneHeaderList();
                for ($i = 0; $i < count($zoneHeaderList); $i++) {
                    if (isset($zoneHeaderList[$i]['vtecList'])) {
                        for ($j = 0; $j < count($zoneHeaderList[$i]['vtecList']); $j++) {
                            if (preg_match('/^(NEW|CON|COR|CAN|EX[ABPT])$/', $zoneHeaderList[$i]['vtecList'][$j]['a'])) {
                                $abbr = $zoneHeaderList[$i]['vtecList'][$j]['p'].$zoneHeaderList[$i]['vtecList'][$j]['s'];
                                $abbrs[$abbr] = $this->getFullNameFromAbbr($abbr);
                            }
                        }
                    } else {
                        $abbrs[$this->getProductType()] = $this->getFullNameFromAbbr($this->getProductType());
                    }
                }
                // No VTECs found, default to product type
                if (empty($abbrs)) {
                    $fullName = 'n/a';
                    if ($this->getAwipsId() === 'SWOMCD') {
                        $abbrs['SWO'] = 'Mesoscale Discussion';
                    } else {
                        $abbrs[$this->getProductType()] = $this->getFullNameFromAbbr($this->getProductType());
                    }
                }
            }
        }

        return $abbrs;
    }

    /**
     * Obtains the product's global information
     *
     * @return string The product's global information
     */
    public function getGlobalInfo($zone = ''): string
    {
        if ($zone === '') {
            return $this->productInfo['globalInfo'];
        } else {
            for ($i = 0; $i < count($this->productInfo['zoneHeaderList']); $i++) {
                foreach ($this->productInfo['zoneHeaderList'][$i]['zoneList'] as $zoneKey => $zoneVal) {
                    if ($zone === $zoneKey) {
                        return $this->productInfo['zoneHeaderList'][$i]['globalInfo'];
                    }
                }
            }
        }

        return '';
    }

    /**
     * Obtains the product's content for the specified zone
     *
     * @param  string  $zone  The specified zone (optional)
     * @return string The product's content
     */
    public function getContent($zone = ''): string
    {
        // var_dump($this->productInfo);
        if ($this->getZoneClass() === 'A') {
            if (! empty($this->productInfo['zoneInfo']['globalInfo'])) {
                return $this->productInfo['zoneInfo']['globalInfo'];
            } else {
                return '';
            }
        } elseif ($this->getZoneClass() === 'B' || $this->getZoneClass() === 'C') {
            for ($i = 0; $i < count($this->productInfo['zoneHeaderList']); $i++) {
                foreach ($this->productInfo['zoneHeaderList'][$i]['zoneList'] as $zoneKey => $zoneVal) {
                    if ($zone === $zoneKey) {
                        return $this->productInfo['zoneHeaderList'][$i]['content'];
                    }
                }
            }
        }

        return '';
    }

    /**
     * Obtains the product's zone header list
     *
     * @return array The product's zone header list
     */
    public function getZoneHeaderList(): array
    {
        return $this->productInfo['zoneHeaderList'];
    }

    /**
     * Obtains a product's SPC number
     *
     * @return int|null The product's SPC number
     */
    public function getSpcNum(): ?int
    {
        return preg_match('/(SEL|WWA|WWP|WOU|WCN|SWO)/', $this->getProductType()) ? $this->getProductSpcNum() : null;
    }

    /**
     * Obtains a SEL's purge time
     *
     * @return int The SEL's purgetime
     */
    public function getSelPurgeTime(): ?int
    {
        return $this->getProductType() === 'SEL' ? $this->getProdSelPurgeTime() : null;
    }

    /**
     * Determines whether a specific product is a PDS watch
     *
     * @return bool TRUE if product is a PDS watch, FALSE otherwise
     */
    public function isPdsWatch(): bool
    {
        return count(preg_grep('/^\.\.\.THIS IS A PARTICULARLY DANGEROUS SITUATION\.\.\./', $this->fileContents)) > 0 ? true : false;
    }

    /**
     * Determines whether a specific product is a full SEL
     *
     * @return bool TRUE is product is a full SEL, FALSE otherwise
     */
    public function isFullSel(): bool
    {
        return count(preg_grep('/THE NWS STORM PREDICTION CENTER HAS ISSUED A/', $this->fileContents)) > 0 ? true : false;
    }

    /**
     * Creates array with all product properties
     *
     * @return array The product data
     */
    public function toArray(): ?array
    {
        if (preg_match('/\.txt$/i', basename($this->fileName))) {
            // Product is a text file
            return [
                'fileName' => $this->getFileName(),
                'productTime' => $this->getProductTime(),
                'wmoHeader' => $this->getWmoHeader(),
                'awipsId' => $this->getAwipsId(),
                'zoneClass' => $this->getZoneClass(),
                'productType' => $this->getProductType(),
                'globalInfo' => $this->getGlobalInfo(),
                'content' => $this->getContent(),
                'fullContent' => implode("\n", $this->fileContents),
                'sfwInfo' => $this->getSfwInfo(),
                'zoneHeaderList' => $this->getZoneHeaderList(),
                'spcNum' => $this->getSpcNum(),
                'selPurgeTime' => $this->getSelPurgeTime(),
                'pdsWatchFlag' => $this->isPdsWatch(),
                'fullSelFlag' => $this->isFullSel(),
                'affectedAreaCoords' => $this->getAffectedAreaCoords(),
                'productSource' => $this->getProductSource(),
                'wfoInfo' => $this->getWfoInfo(),
                'abbrs' => $this->getProductAbbrs(),
                'generatedFileNames' => [
                    'emwin' => $this->getEmwinFileName(),
                    'nwws' => $this->getNwwsFileName(),
                ],
            ];
        } elseif (preg_match('/-([A-Z0-9]+)\.(JPG|GIF|PNG)$/', basename($this->fileName), $matches)) {
            // Product is a graphic file
            return [
                'fileName' => $this->getFileName(),
                'productTime' => $this->getProductTime(),
                'wmoHeader' => '',
                'awipsId' => '------',
                'zoneClass' => '',
                'productType' => $matches[1],
                'globalInfo' => '',
                'content' => '',
                'fullContent' => '',
                'sfwInfo' => [],
                'zoneHeaderList' => [],
                'spcNum' => 0,
                'selPurgeTime' => 0,
                'pdsWatchFlag' => false,
                'fullSelFlag' => false,
                'affectedAreaCoords' => [],
                'productSource' => $this->getProductSource(),
                'wfoInfo' => [],
                'abbrs' => [],
                'generatedFileNames' => [
                    'emwin' => basename($this->fileName),
                    'nwws' => '',
                ],
            ];
        } else {
            throw new \Exception('Invalid product file');

            return null;
        }
    }

    /**
     * Obtains the product's time
     *
     * @return int The product's creation time, or 0 if file does not exist
     */
    public function getProductTime(): int
    {
        return file_exists($this->fileName)
            ? filectime($this->fileName)
            : 0;
    }

    /**
     * Obtains a product's affected area polyon coordinates
     *
     * @return array The product's affected coordinates
     */
    public function getAffectedAreaCoords(): array
    {
        $latLonLine = '';
        $latLonFound = 0;
        if (preg_match('/(EWW|FFS|FFW|NPW|MWS|SMW|SVR|SVS|TOR|SPS|WSW)/', $this->getProductType())) {
            // Example:
            //
            // LAT...LON 3982 8066 3989 8064 3992 8047 3978 8050
            //
            // Loop through the lines of the product and look for affected area coordinates
            for ($i = 0; $i < count($this->fileContents); $i++) {
                // print "**DEBUG** \$this->fileContents[$i] = " . $this->fileContents[$i] . "<br />\n";
                if (preg_match('/^LAT\.\.\.LON/', $this->fileContents[$i])) {
                    $latLonFound = 1;
                    $latLonLine .= $this->fileContents[$i];
                }
                if (preg_match('/^(\s*)(\d+)/', $this->fileContents[$i]) && $latLonFound) {
                    $tmpline = $this->fileContents[$i];
                    $tmpline = preg_replace('/^(\s*)/', ' ', $tmpline);
                    $latLonLine .= $tmpline;
                }
                if (preg_match('/^TIME/', $this->fileContents[$i]) || preg_match('/^(\s*)$/', $this->fileContents[$i]) && $latLonFound) {
                    break;
                }
            }
            if (! $latLonFound) {
                return [];
            }
            $latLonLine = preg_replace('/\r/', '', $latLonLine);
            $latLonLine = preg_replace('/\n/', '', $latLonLine);
            $latLonLine = preg_replace('/^LAT\.\.\.LON /', '', $latLonLine);
            // Give them the appropriate format
            // print "**DEBUG** \$latLonLine = $latLonLine\n";
            $latLonParts = preg_split('/\s+/', $latLonLine);
            for ($i = 0; $i < count($latLonParts); $i += 2) {
                $lat = '';
                if (strlen($latLonParts[$i]) === 4) {
                    $lat = substr($latLonParts[$i], 0, 2).'.'.substr($latLonParts[$i], 2);
                }
                if (strlen($latLonParts[$i]) === 5) {
                    $lat = substr($latLonParts[$i], 0, 3).'.'.substr($latLonParts[$i], 3);
                }
                if (! isset($latLonParts[$i])) {
                    break;
                }
                $latLonParts[$i] = $lat;
                $lon = '';
                if (isset($latLonParts[$i + 1])) {
                    if (strlen($latLonParts[$i + 1]) === 4) {
                        $lon = substr($latLonParts[$i + 1], 0, 2).'.'.substr($latLonParts[$i + 1], 2);
                    }
                    if (strlen($latLonParts[$i + 1]) === 5) {
                        $lon = substr($latLonParts[$i + 1], 0, 3).'.'.substr($latLonParts[$i + 1], 3);
                    }
                    $latLonParts[$i + 1] = floatval($lon) * -1;
                }
            }
            $latLonArray = [];
            for ($i = 0; $i < count($latLonParts); $i += 2) {
                if (isset($latLonParts[$i + 1])) {
                    array_push($latLonArray, [
                        'lat' => floatval($latLonParts[$i]),
                        'lon' => floatval($latLonParts[$i + 1]),
                    ]);
                }
            }

            return $latLonArray;
        } elseif ($this->getAwipsId() === 'SWOMCD') {
            // Loop through the lines of the product and look for MCD coordinates
            for ($i = 0; $i < count($this->fileContents); $i++) {
                // print "**DEBUG** \$fileContents[$i] = " . $fileContents[$i] . "\n";
                if (preg_match('/^LAT\.\.\.LON/', $this->fileContents[$i])) {
                    // print "**DEBUG** lat/lon line found\n";
                    $latLonFound = 1;
                    $latLonLine .= $this->fileContents[$i];
                }
                if (preg_match('/^(\s*)(\d+)/', $this->fileContents[$i]) && $latLonFound) {
                    $tempLine = $this->fileContents[$i];
                    $this->fileContents[$i] = preg_replace('/^(\s*)/', '', $tempLine);
                    $latLonLine .= $tempLine;
                }
                if (preg_match('/^(\s*)$/', $this->fileContents[$i]) && $latLonFound) {
                    // print "**DEBUG** break out of for loop\n";
                    break;
                }
            }
            // Example:
            //
            // LAT...LON   38977651 39577565 40817459 41747372 42277120 41787083
            //             41517104 41137149 40857244 40597339 40537392 40507407
            //             40437398 39837406 39387435 39207467 38937484 38927512
            //             38717509 38717586 38977651
            //
            $latLonLine = preg_replace('/^LAT\.\.\.LON /', '', $latLonLine);
            $latLonLine = preg_replace('/\r/', '', $latLonLine);
            $latLonLine = preg_replace('/\n/', '', $latLonLine);
            $latLonLine = preg_replace('/^(\s*)/', '', $latLonLine);
            $latLonLine = preg_replace('/(\s*)$/', '', $latLonLine);
            // print "**DEBUG** \$latLonLine = #$latLonLine#\n";
            // Give them the appropriate format
            $latLonParts = preg_split('/\s+/', $latLonLine);
            for ($i = 0; $i < count($latLonParts); $i++) {
                $lat = substr($latLonParts[$i], 0, 2).'.'.substr($latLonParts[$i], 2, 2);
                $lon1 = substr($latLonParts[$i], 4, 2);
                $lon2 = substr($latLonParts[$i], 6, 2);
                if ($lon1 < 25) {
                    $lon1 = '1'.$lon1;
                }
                $lon = $lon1.'.'.$lon2;
                $latLonParts[$i] = $lat.' -'.$lon;
            }
            $latLonArray = [];
            for ($i = 0; $i < count($latLonParts); $i++) {
                [$lat, $lon] = explode(' ', $latLonParts[$i]);
                array_push($latLonArray, [
                    'lat' => floatval($lat),
                    'lon' => floatval($lon),
                ]);
            }

            return $latLonArray;
        } else {
            return [];
        }
    }

    /**
     * Obtains data about WFO that issued the product
     *
     * @return ?array The product's time
     */
    public function getWfoInfo(): ?array
    {
        // var_dump(substr($this->getAwipsId(), 3, 3));
        // First, try to grab WFO from the AWIPS ID
        try {
            $wfo = Wfo::where('abbr', '=', substr($this->getAwipsId(), 3, 3))->firstOrFail();

            return $wfo->toArray();
        } catch (ModelNotFoundException $e) {
            // throw new \Exception('Could not find WFO info!');
            // return NULL;
        }
        // If we got here, try to grab it from the WMO Header
        if ($this->getWmoHeader() !== '') {
            $wmoHeader = $this->getWmoHeader();
            if ($wmoHeader !== 'n/a') {
                $fullWfo = (explode(' ', $wmoHeader))[1];
            } else {
                // Couldn't obtrain WFO from the WMO Header, get it from the filename
                $fullWfo = getProductWfo(basename($this->fileName));
            }
            try {
                $wfo = Wfo::where('abbr', '=', substr($fullWfo, 1, 3))->firstOrFail();

                return $wfo->toArray();
            } catch (ModelNotFoundException $e) {
                // throw new \Exception('Could not find WFO info!');
                // return NULL;
            }
        }

        // If we got here, WFO could not be determined so just return blanks for everything
        return [
            'abbr' => '',
            'fullname' => '',
            'state' => '',
            'tz' => '',
            'url' => '',
            'rid' => '',
        ];
    }

    /**
     * Generate EMWIN file name based on the product data
     *
     * @return ?string The product's EMWIN file name
     */
    public function getEmwinFileName(): ?string
    {
        // A_WWUS73KLOT051551_C_KWIN_20220705155211_857348-1-NPWLOTIL.TXT
        // Z_NOXX10KWBC160815_C_KWIN_20250316201518_711727-4-EPHTWOUS.TXT
        // Z_QATA00KWBC160815_C_KWIN_20250316201519_711738-3-RADNTHES.GIF
        if (preg_match('/^[AZ]_/', basename($this->fileName))) {
            return basename($this->fileName);
        }
        try {
            $wfoInfo = $this->getWfoInfo();
        } catch (\Exception $e) {
            throw $e;

            return null;
        }
        // Only want the first three fields of the WMO Header (most only have 3)
        $wmoHeaderParts = explode(' ', $this->getWmoHeader());
        if (isset($wmoHeaderParts[0]) && isset($wmoHeaderParts[1]) && isset($wmoHeaderParts[2])) {
            $wmoHeader = $wmoHeaderParts[0].$wmoHeaderParts[1].$wmoHeaderParts[2];
        } else {
            throw new \Exception('Invalid WMO Header format! (filename='.$this->fileName.')');

            return null;
        }
        // If SFW, priority = 1, otherwise priority = 2
        $priority = preg_match('/^(TOR|SVR|SVS|FFW|FFS|EWW)$/', $this->getProductType()) ? 1 : 2;

        // Return name
        return 'A_'.
            $wmoHeader.                                    // WMO Header w/o spaces
            '_C_KWIN_'.                                    // Product source
            date('YmdHis', filectime($this->fileName)).    // Product UTC Date (YYYYMMDDhhmmss)
            '_'.                                           // Delimiter
            substr(strval(time()), -6).                    // 6-digit seq. number
            '-'.                                           // Delimiter
            $priority.                                     // Priority
            '-'.                                           // Delimiter
            (strlen($this->getAwipsId()) === 6 ? $this->getAwipsId().$wfoInfo['state'] : $this->getAwipsId()). // AWIPS ID
            '.TXT';                                         // File extension
    }

    /**
     * Generate NWWS file name based on the product data
     *
     * @return string The product's NWWS-OI file name
     */
    public function getNwwsFileName(): string
    {
        // klot_wwus83-spslot.060231_13046.24078.txt
        $wmoHeader = explode(' ', $this->getWmoHeader());
        $awipsId = $this->getAwipsId();
        if (count($wmoHeader) > 1) {
            // If this is already a NWWS product, just return the filename
            if (preg_match("/^$wmoHeader[1]/", basename($this->fileName))) {
                return basename($this->fileName);
            }
        } else {
            $wmoHeader = [
                substr(basename($this->fileName), 2, 6),
                getProductWfo(basename($this->fileName)),     // WFO
                substr(basename($this->fileName), 12, 6),
            ];
            if (preg_match('/\-([A-Z0-9]+)\.TXT$/', basename($this->fileName), $matches)) {
                $awipsId = substr($matches[1], 0, 6);
            }
        }

        // Build file name
        return strtolower(
            $wmoHeader[1].                                 // WFO
            '_'.                                           // Static string
            $wmoHeader[0].                                 // First part of WMO Header
            '-'.                                           // Static string
            $awipsId.                                      // AWIPS ID
            '.'.                                           // Static string
            date('dHi', filectime($this->fileName)).       // Product UTC Date (DDhhmm)
            '_'.                                           // Static string
            substr(strval(time()), 0, 5).                  // 5-digit seq. number #1
            '.'.                                           // Static string
            substr(strval(time()), -5).                    // 5-digit seq. number #2
            '.txt');                                        // File extension
    }

    // ///////////////////////////////////////////////////////////////////////////////
    // Private functions
    // ///////////////////////////////////////////////////////////////////////////////

    /**
     * Gets the product's contents, removing some unprintable binary characters
     *
     * @param  array  $fileContents  The product file's content with unprintable characters
     * @return array The product's contents
     */
    private function parseFileContents($fileContents): array
    {
        for ($i = 0; $i < count($fileContents); $i++) {
            // Strip out binary characters
            $fileContents[$i] = preg_replace('/\r/', '', $fileContents[$i]);
            $fileContents[$i] = preg_replace('/\x00/', '', $fileContents[$i]);
            $fileContents[$i] = preg_replace('/\x03/', '', $fileContents[$i]);
            $fileContents[$i] = preg_replace('/\&lt;/', '<', $fileContents[$i]);
            $fileContents[$i] = preg_replace('/\&gt;/', '>', $fileContents[$i]);
            $fileContents[$i] = preg_replace('/\&amp;/', '&', $fileContents[$i]);
            $fileContents[$i] = preg_replace('/[^\x20-\x7E]/', '', $fileContents[$i]);
        }

        return $fileContents;
    }

    /**
     * Converts a GMT time in abbreviated format to UNIX timestamp
     *
     * @param  string  $gmtAbbrTime  The GMT time in DDHHMM format
     * @return int The corresponding UNIX timestamp
     */
    private function buildFullGmtTime($gmtAbbrTime): int
    {
        $fullGmtTime = 0;
        $hourDiff = 4;	// default
        // print "**DEBUG** \$gmt_abbr_time = $gmt_abbr_time\n";
        // Convert the day/time if it matches the correct format
        $fileGmtime = $this->getProductTime();
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $gmtAbbrTime, $matches)) {
            $purgeGmtDay = intval($matches[1]);
            $purgeGmtHour = intval($matches[2]);
            $purgeGmtMin = intval($matches[3]);
            $purgeGmtMon = 0;
            $purgeGmtYear = 0;
            // print "**DEBUG** \$purgeGmtMin  = $purgeGmtMin\n";
            // print "**DEBUG** \$purgeGmtHour = $purgeGmtHour\n";
            // print "**DEBUG** \$purgeGmtDay  = $purgeGmtDay\n";
            // print "**DEBUG** gmdate('Y-m-d H:i:s', $file_gmtime) = " . gmdate('Y-m-d H:i:s \G\M\T', $file_gmtime) . "\n";
            $fileGmtSec = intval(gmdate('s', $fileGmtime));
            $fileGmtMin = intval(gmdate('i', $fileGmtime));
            $fileGmtHour = intval(gmdate('H', $fileGmtime));
            $fileGmtDay = intval(gmdate('d', $fileGmtime));
            // print "**DEBUG** \$fileGmtMin  = $fileGmtMin\n";
            // print "**DEBUG** \$fileGmtHour = $fileGmtHour\n";
            // print "**DEBUG** \$fileGmtDay  = $fileGmtDay\n";
            if ($purgeGmtDay > $fileGmtDay) {
                // print "**DEBUG** \$purge_gmt_day > $file_gmt_day\n";
                $hourDiff = (24 * abs($purgeGmtDay - $fileGmtDay)) - ($fileGmtHour - $purgeGmtHour);
                if ($hourDiff > 24) {
                    $hourDiff = 24;
                }
            } elseif ($purgeGmtDay === $fileGmtDay) {
                if ($purgeGmtHour < $fileGmtHour) {
                    // Example:
                    //   file date/time:    01/01/2008 01:00
                    //   purge date/time:   01/01/2008 00:00
                    // Purge date before file date?!?!, i.e. this should never happen!
                } elseif ($purgeGmtHour === $fileGmtHour) {
                    // Example:
                    //   file date/time:    01/01/2008 01:00
                    //   purge date/time:   01/01/2008 01:00
                    $hourDiff = 0;
                } else {	// ($purge_gmt_hour > $file_gmt_hour)
                    // Example:
                    //   file date/time:    01/01/2008 00:00
                    //   purge date/time:   01/01/2008 12:00
                    $hourDiff = $purgeGmtHour - $fileGmtHour;
                }
            } else {	// ($purge_gmt_day < $file_gmt_day)
                $hourDiff = 24 - ($fileGmtHour - $purgeGmtHour);
                if ($hourDiff > 24) {
                    $hourDiff = 24;
                }
            }
            // print "**DEBUG** \$hourDiff = $hourDiff\n";
            // print "**DEBUG** (stat(\$filename))[10] = " . (stat($filename))[10] . "\n";
            $fullGmtTime = $fileGmtime + (60 * 60 * $hourDiff) + (60 * ($purgeGmtMin - $fileGmtMin)) - $fileGmtSec;
        } else {
            $fullGmtTime = $fileGmtime + (60 * 60 * $hourDiff);
        }

        // print "**DEBUG** \$fullGmtTime = $fullGmtTime\n";
        return $fullGmtTime;
    }

    /**
     * Obtain an array of text product's fullnames from the product's contents
     *
     * @param  array  $productInfo  The product's info
     * @param  string  $vtecKey  VTEC key
     * @return array The product's full titles
     */
    private function getFullTitles($productInfo, $vtecKey): array
    {
        $fullName = '[Unknown]';
        // Get the default full name
        $textProductResults = TextProduct::where('abbr', '=', $productInfo['productType'])->first();
        // var_dump($results);
        if (! empty($textProductResults)) {
            $fullName = $textProductResults->fullname;
        }
        // Count number of VTEC lines in product
        $vtecCount = count(preg_grep('/^\/\w\.\w{3}\.\w{4}\.\w{2}\.\w\.\d{4}\.[0-9TZ]{12}-[0-9TZ]{12}\/$/', $this->fileContents));
        // print "**DEBUG** \$vtec_count = $vtecCount\n";
        $titlesArray = [];
        if ($vtecCount === 0) {
            // Product does not have a VTEC line, obtain the full title from the product's filename
            if ($this->getProductType() === 'SEL') {
                if (count(preg_grep('/TORNADO WATCH/i', $this->fileContents)) > 0) {
                    $fullName = 'TORNADO WATCH';
                } elseif (count(preg_grep('/SEVERE THUNDERSTORM WATCH/i', $this->fileContents)) > 0) {
                    $fullName = 'SEVERE THUNDERSTORM WATCH';
                }
            } else {
                if (count(preg_grep('/^DAY 1 CONVECTIVE OUTLOOK/i', $this->fileContents)) > 0) {
                    $fullName = 'DAY 1 CONVECTIVE OUTLOOK';
                } elseif (count(preg_grep('/^DAY 2 CONVECTIVE OUTLOOK/i', $this->fileContents)) > 0) {
                    $fullName = 'DAY 2 CONVECTIVE OUTLOOK';
                } elseif (count(preg_grep('/^DAY 3 CONVECTIVE OUTLOOK/i', $this->fileContents)) > 0) {
                    $fullName = 'DAY 3 CONVECTIVE OUTLOOK';
                } elseif (count(preg_grep('/^DAY 4-8 CONVECTIVE OUTLOOK/i', $this->fileContents)) > 0) {
                    $fullName = 'DAY 4-8 CONVECTIVE OUTLOOK';
                } elseif (count(preg_grep('/^MESOSCALE DISCUSSION/i', $this->fileContents)) > 0) {
                    $fullName = 'MESOSCALE DISCUSSION';
                } elseif (count(preg_grep('/AMBER ALERT/i', $this->fileContents)) > 0 && count(preg_grep('/CANCEL/', $this->fileContents)) > 0) {
                    $fullName = 'CHILD ABDUCTION EMERGENCY CANCELLATION';
                }
                $fullName = ucwords(strtolower($fullName));
            }
            array_push($titlesArray, ['title' => $fullName, 'purgeTime' => 'n/a']);
        } else {
            // Product has a VTEC line, determine full title from VTEC contents
            if ($vtecKey === '') {
                $contents = $this->fileContents;
            } else {
                $contents = preg_split('/\n/', $productInfo['zoneInfo'][$vtecKey]);
            }
            // Example VTEC line
            //
            // /O.NEW.KLSX.WW.Y.0007.071207T0000Z-071207T0600Z/
            //
            for ($i = 0; $i < count($contents); $i++) {
                if (preg_match('/^\/\w{5}\.\w\.\w{2}\./', $contents[$i])) {
                    continue;
                }
                if (preg_match('/^\/(\w)\.(\w+)\.(\w+)\.(\w+)\.(\w)\.(\d+)\.(.*)-(.*)\/$/', $contents[$i], $matches)) {
                    if ($productInfo['productType'] === 'SVS' && $matches[2] !== 'CAN') {
                        array_push($titlesArray, ['title' => 'Severe Weather Statement', 'purge_time' => $matches[8]]);
                    } else {
                        $vtecPhenomenaResults = VtecPhenomena::where('abbr', '=', $matches[4])->first();
                        // var_dump($vtecPhenomenaResults);
                        $vtecPhenomena = (! empty($vtecPhenomenaResults) ? $vtecPhenomenaResults->fullname : '(Unknown)');
                        $vtecSignificanceResults = VtecSignificance::where('abbr', '=', $matches[5])->first();
                        // var_dump($vtecSignificanceResults);
                        $vtecSignificance = (! empty($vtecSignificanceResults) ? $vtecSignificanceResults->fullname : '(Unknown)');
                        $fullName = $vtecPhenomena.' '.$vtecSignificance;
                        if ($matches[2] === 'CAN') {
                            $fullName .= ' Cancellation';
                        }
                        if ($matches[2] === 'EXP') {
                            $fullName .= ' Expiration';
                        }
                        if ($matches[1] === 'T') {
                            $fullName = 'Test '.$fullName;
                        }
                        array_push($titlesArray, ['title' => $fullName, 'purgeTime' => $matches[8]]);
                    }
                }
            }
        }

        return $titlesArray;
    }

    /**
     * Gets the full name and state from a zone or fips code
     *
     * @param  string  $zoneOrFips  The 6-character zone or FIPS code
     * @return array The county or zone full name
     */
    private function getCountyZoneName(string $zoneOrFips): ?array
    {
        // print "**DEBUG** \$zoneOrFips = $zoneOrFips\n";
        if (preg_match('/^\w{2}C\w{3}$/', $zoneOrFips)) {
            // Check counties
            $state = State::where('state', '=', substr($zoneOrFips, 0, 2))->first();
            if (! empty($state)) {
                $county = County::where('fips', '=', $state->fips.substr($zoneOrFips, 3, 3))->first();
                if (! empty($county)) {
                    return [
                        'fullName' => $county->countyname.' Co., '.$county->state,
                        'countyName' => $county->countyname,
                        'state' => $county->state,
                        'lat' => floatval($county->lat),
                        'lon' => floatval($county->lon),
                        'wfo' => $county->cwa,
                    ];
                }
            }
        } elseif (preg_match('/^\w{2}Z\d{3}$/', $zoneOrFips)) {
            // Check regular zones
            $zone = Zone::where([
                ['zone', '=', substr($zoneOrFips, 3, 3)],
                ['state', '=', substr($zoneOrFips, 0, 2)],
            ])->first();
            if (! empty($zone)) {
                return [
                    'fullName' => $zone->name.' Co., '.$zone->state,
                    'name' => $zone->name,
                    'state' => $zone->state,
                    'lat' => floatval($zone->lat),
                    'lon' => floatval($zone->lon),
                    'wfo' => $zone->cwa,
                ];
            } else {
                // Check fire zones
                $fireZone = FireZone::where([
                    ['zone', '=', substr($zoneOrFips, 3, 3)],
                    ['state', '=', substr($zoneOrFips, 0, 2)],
                ])->first();
                if (! empty($fireZone)) {
                    return [
                        'fullName' => $fireZone->name,
                        'lat' => floatval($fireZone->lat),
                        'lon' => floatval($fireZone->lon),
                        'wfo' => $fireZone->cwa,
                    ];
                } else {
                    // Check marine zones
                    $marineZone = MarineZone::where('id', '=', $zoneOrFips)->first();
                    if (! empty($marineZone)) {
                        return [
                            'fullName' => $marineZone->name,
                            'lat' => floatval($marineZone->lat),
                            'lon' => floatval($marineZone->lon),
                            'wfo' => $marineZone->wfo,
                        ];
                    } else {
                        // Check offshore zones
                        $offshoreZone = OffshoreZone::where('id', '=', $zoneOrFips)->first();
                        if (! empty($offshoreZone)) {
                            return [
                                'fullName' => $offshoreZone->name,
                                'lat' => floatval($offshoreZone->lat),
                                'lon' => floatval($offshoreZone->lon),
                                'wfo' => $offshoreZone->wfo,
                            ];
                        } else {
                            // Could not find county or zone in database, skip it and return NULL
                        }
                    }
                }
            }
        }

        // throw new \Exception('Could not find county or zone!');
        return null;
    }

    /**
     * Obtain a product's associated SEL or Mesoscale Discussion number
     *
     * @return ?int The product's SEL or MD number
     */
    private function getProductSpcNum(): ?int
    {
        $spcNum = 0;
        if ($this->getProductType() === 'SEL') {
            $tmparray = preg_grep('/[A-Z ]+ WATCH NUMBER (\d+)\s*$/i', $this->fileContents);
            if (count($tmparray) > 0) {
                $spcNum = array_shift($tmparray);
                $spcNum = preg_replace('/[A-Z ]+ WATCH NUMBER (\d+)(\s*)/i', '$1', $spcNum);
            }
            // Cancellations
            $tmparray = preg_grep('/[A-Z ]+ WATCH NUMBER (\d+) ISSUED AT/i', $this->fileContents);
            if (count($tmparray) > 0) {
                $spcNum = array_shift($tmparray);
                $spcNum = preg_replace('/[A-Z ]+ WATCH NUMBER (\d+) ISSUED AT (.*)/i', '$1', $spcNum);
            }
        } elseif ($this->getProductType() === 'WWA') {
            $tmparray = preg_grep('/STATUS REPORT ON WW (\d+)\s*$/i', $this->fileContents);
            if (count($tmparray) > 0) {
                $spcNum = array_shift($tmparray);
                $spcNum = preg_replace('/STATUS REPORT ON WW (\d+)(\s*)/', '$1', $spcNum);
            }
        } elseif ($this->getProductType() === 'WOU') {
            $tmparray = preg_grep('/WATCH OUTLINE UPDATE FOR W[ST] (\d+)/i', $this->fileContents);
            if (count($tmparray) > 0) {
                $spcNum = array_shift($tmparray);
                $spcNum = preg_replace('/WATCH OUTLINE UPDATE FOR W[ST] (\d+)/', '$1', $spcNum);
            }
        } elseif ($this->getProductType() === 'WCN') {
            $tmparray = preg_grep('/WATCH COUNTY NOTIFICATION FOR WATCH (\d+)\s*$/i', $this->fileContents);
            if (count($tmparray) > 0) {
                $spcNum = array_shift($tmparray);
                $spcNum = preg_replace('/WATCH COUNTY NOTIFICATION FOR WATCH (\d+)(\s*)/', '$1', $spcNum);
            }
        } elseif ($this->getProductType() === 'WWP') {
            $tmparray = preg_grep('/WATCH PROBABILITIES FOR WT (\d+)\s*$/i', $this->fileContents);
            if (count($tmparray) > 0) {
                $spcNum = array_shift($tmparray);
                $spcNum = preg_replace('/WATCH PROBABILITIES FOR WT (\d+)(\s*)/', '$1', $spcNum);
                $spcNum = preg_replace('/TORNADO /', '', $spcNum);
                $spcNum = preg_replace('/SEVERE THUNDERSTORM /', '', $spcNum);
            }
        } elseif ($this->getAwipsId() === 'SWOMCD') {
            $tmparray = preg_grep('/MESOSCALE DISCUSSION (\d+)\s*$/i', $this->fileContents);
            if (count($tmparray) > 0) {
                $spcNum = array_shift($tmparray);
                $spcNum = preg_replace('/MESOSCALE DISCUSSION (\d+)\s*/i', '$1', $spcNum);
            }
        } elseif ($this->getProductType() === 'SAW') {
            $tmparray = preg_grep('/^WW (\d+) /i', $this->fileContents);
            if (count($tmparray) > 0) {
                $spcNum = array_shift($tmparray);
                $spcNum = preg_replace('/^WW (\d+) /', '$1', $spcNum);
            }
        } else {
            // throw new \Exception('Product does not have an SPC number! (' . $this->getFileName() . ')');
            return null;
        }

        return intval($spcNum);
    }

    /**
     * Obtains a list of the product's VTEC lines (if any)
     *
     * @param  string  $prodStanza  The one or more lines containing the VTEC lines
     * @return array The product's VTEC lines
     */
    private function getVtecList($prodStanza): array
    {
        $prodStanzaLines = explode("\n", $prodStanza);
        $vtecArray = [];
        for ($i = 0; $i < count($prodStanzaLines); $i++) {
            if (preg_match('/^\/\w{5}\.\w\.\w{2}\./', $prodStanzaLines[$i])) {
                continue;
            }
            if (preg_match('/^\/(\w)\.(\w+)\.(\w+)\.(\w+)\.(\w)\.(\d+)\.(.*)-(.*)\/$/', $prodStanzaLines[$i], $matches)) {
                $tmphash = [];
                $retval1 = VtecClass::where('abbr', '=', $matches[1])->first();
                $tmphash['class'] = $retval1->fullname;
                $tmphash['c'] = $matches[1];
                $retval2 = VtecAction::where('abbr', '=', $matches[2])->first();
                $tmphash['action'] = $retval2->fullname;
                $tmphash['a'] = $matches[2];
                $tmphash['officeId'] = $matches[3];
                $retval3 = VtecPhenomena::where('abbr', '=', $matches[4])->first();
                if (! empty($retval3)) {
                    $tmphash['phenomena'] = $retval3->fullname;
                } else {
                    $tmphash['phenomena'] = '??';
                    Log::error('AWIPSProduct: Could not determine VTEC Stanza', [
                        'fileName' => $this->fileName,
                        'stanza' => $prodStanza,
                    ]);
                }
                $tmphash['p'] = $matches[4];
                $retval4 = VtecSignificance::where('abbr', '=', $matches[5])->first();
                if (empty($retval4->fullname)) {
                    Log::error('AWIPSProduct: Could not determine VTEC significance.', [
                        'fileName' => $this->fileName,
                        'matches' => $matches[0],
                    ]);
                    $tmphash['significance'] = 'n/a';
                } else {
                    $tmphash['significance'] = $retval4->fullname;
                }
                $tmphash['s'] = $matches[5];
                $tmphash['etn'] = $matches[6];
                $tmphash['beginDateTime'] = $matches[7];
                $tmphash['endingDateTime'] = $matches[8];
                array_push($vtecArray, $tmphash);
            }
        }

        return $vtecArray;
    }

    /**
     * Splits product info into parts to make it easier to process
     *
     * @return array The product's info
     *
     * array contents:
     *
     * 'wmoHeader'        WMO header (usually first line)
     * 'awipsId'          Product abbr and product designator (usually second line)
     * 'zoneClass'        zone class (A, B, or C)
     * 'globalInfo'       Information that has no zone associated with it
     * 'productType'      The 3-letter abbreviation of the product type
     * '<zoneHeader>'     The zone-specific product data
     */
    private function splitProductInfo(bool $graphicsFlag): array
    {
        $prodInfo = [];
        $key = 'globalInfo';
        $zone = '';
        $hdr1 = -1;
        $hdr2 = -1;
        $eofFound = 0;
        $scanZone = 0;
        $scanLine = 1;
        $infoLines = [];
        if ($graphicsFlag) {
            $awipsId = null;
            if (preg_match('/\-([A-Z0-9]+)\.(JPG|GIF|PNG)$/', basename($this->fileName), $matches)) {
                $awipsId = $matches[1];
            }

            return [
                'wmoHeader' => null,
                'awipsId' => $awipsId,
                'zoneClass' => 'A',
                'globalInfo' => null,
                'productType' => substr($awipsId, 0, 3),
                'zoneInfo' => [],
            ];
        }
        // print "**DEBUG** Number of lines in \$prod_lines = " . scalar(@$prod_lines) . "\n";
        // print "**DEBUG** \$prod_lines->[0] = " . $prod_lines->[0] . "\n";
        $prodInfo['zoneClass'] = 'A';
        $prodInfo['globalInfo'] = '';
        $prodInfo['zoneInfo'] = [];
        // Loop through the lines of the product and parse out the information
        for ($i = 0; $i < count($this->fileContents); $i++) {
            if ($hdr1 === -1) {
                // FLUS44 KEWX 212144 CCA
                if (preg_match('/^\w{4}\d{2}\s+\w{4}\s+\d{6}(\s+\w+)?\s*$/', $this->fileContents[$i], $matches)) {
                    // WMO HEADER
                    $prodInfo['wmoHeader'] = $this->fileContents[$i];
                    // print "**DEBUG** WMO header found at array index $i ('" . $prodInfo['wmoHeader'] . "')\n";
                    $hdr1 = $i;
                }
            }
            if ($hdr2 === -1) {
                if (preg_match('/^[A-Z0-9]{4,6}\s*$/', $this->fileContents[$i]) && ! preg_match('/METAR/', $this->fileContents[$i])) {
                    // AWIPS ID
                    $prodInfo['awipsId'] = $this->fileContents[$i];
                    $prodInfo['awipsId'] = preg_replace('/(\s*)/', '', $prodInfo['awipsId']);
                    // print "**DEBUG** AWIPS ID found at array index $i ('" . $prodInfo['awipsId'] . "')\n";
                    $hdr2 = $i;
                    $i++;
                }
            }
            // print "**DEBUG** \$hdr1 = $hdr1\n";
            // print "**DEBUG** \$hdr2 = $hdr2\n";
            // If we found the WMO header and the AWIPS ID, start scanning the product info
            if ($hdr1 >= 0 && $hdr2 >= 0) {
                // Check to make sure line being checked in file exists, if it doesn't break out of loop
                if (! isset($this->fileContents[$i])) {
                    break;
                }
                // Check for zone header
                if (preg_match('/^(\w)(\w)(C|Z)(\d+)[->]/', $this->fileContents[$i]) && substr($prodInfo['awipsId'], 0, 3) != 'AFD') {
                    // print "**DEBUG** Beginning of zone header found at line $i\n";
                    $eofFound = 0;
                    if ($i === $hdr2 + 1) {
                        $prodInfo['zoneClass'] = 'B';
                    } else {
                        $prodInfo['zoneClass'] = 'C';
                    }
                    // Loop and read lines until you find the end of the zone header
                    $rc = 0;
                    while (1) {
                        // print "**DEBUG** \$prod_lines[$i] = $prod_lines[$i]\n";
                        if (! isset($this->fileContents[$i])) {
                            break;
                        }
                        $zone .= $this->fileContents[$i];
                        if (preg_match('/[-]*\d{6}[-]*\s*$/', $this->fileContents[$i])) {
                            // print "**DEBUG** End of zone header found at line $i\n";
                            $zone = preg_replace('/ /', '', $zone);
                            $key = $zone;
                            $i++;
                            break;
                        }
                        if (preg_match('/^\s*$/', $this->fileContents[$i])) {
                            // print "**DEBUG** Found blank line at index $i, breaking out of while loop.\n";
                            // print "**DEBUG** Found blank line at index $i, skipping.\n";
                            // key = $prod_lines->[$i-1];
                            // i++;
                            // last;
                        }
                        $rc++;
                        if ($rc === 10) {
                            // print "**DEBUG** Warning!  Endless loop caught in finding end of zone header\n";
                            break;
                        }
                        $i++;
                    }
                    // Check to make sure that the current line exists
                    if (! isset($this->fileContents[$i])) {
                        break;
                    }
                    // If there is already information in $infoLines, place it in $prodInfo['globalInfo'] and clean $infoLines array
                    if (count($infoLines) > 0 && $prodInfo['globalInfo'] === '') {
                        // print "**DEBUG** There is data in \$infoLines\n";
                        $prodInfo['globalInfo'] = implode("\n", $infoLines)."\n";
                        // unset($infoLines);
                        $infoLines = [];
                    }
                }
                // Check for end of info delimiter
                if (preg_match('/^\$\$\s*$/', $this->fileContents[$i]) && ! $eofFound) {
                    $eofFound = 1;
                    // print "**DEBUG** End of info delim found\n";
                    $scanZone = 0;
                    $zone = '';
                    // print "**DEBUG** Inserting info into \$prodInfo[$key]\n";
                    $prodInfo['zoneInfo'][$key] = implode("\n", $infoLines);
                    // unset($infoLines);
                    $infoLines = [];
                }
                // print "**DEBUG** Adding line to $prod_lines array\n";
                // Add the line to the @prod_lines array
                if (! preg_match('/^\$\$\s*$/', $this->fileContents[$i])) {
                    array_push($infoLines, $this->fileContents[$i]);
                }
            }
        }
        // print "**DEBUG** Number of lines in $infoLines = " . count($infoLines) . "\n";
        //
        // Sometimes text products don't contain the EOD delimeter and it also
        // does not contain any zone information.  All the info in the $infoLines
        // array needs to be put in $prodInfo['global data']
        //
        // print "**DEBUG** \$prodInfo['globalInfo'] = " . $prodInfo['globalInfo'] . "\n";
        // print "**DEBUG** \$eofFound               = " . $eofFound . "\n";
        // print "**DEBUG** \$prodInfo['zoneClass']  = " . $prodInfo['zoneClass'] . "\n";
        if ($prodInfo['globalInfo'] === '' && ! $eofFound && $prodInfo['zoneClass'] != 'B') {
            // print "**DEBUG** Setting globalInfo\n";
            $prodInfo['globalInfo'] = implode("\n", $this->fileContents);
        }
        // If we didn't find the EOD delimeter and there is data in the $infoLines
        // array, it needs to be added to the $prodInfo[$key] hash.
        if ($key !== 'globalInfo' && ! $eofFound) {
            $prodInfo['zoneInfo'][$key] = implode("\n", $infoLines);
        }
        // If the WMO header wasn't found, give it a default value
        if (! isset($prodInfo['wmoHeader'])) {
            Log::info('AWIPSProduct: Could not determine WMO Header. ('.$this->fileName.')');
            $prodInfo['wmoHeader'] = 'n/a';
        }
        // If the AWIPS ID wasn't found, give it a default value
        if (! isset($prodInfo['awipsId'])) {
            // Log if AWIPS ID was not found in EMWIN product
            // if (!preg_match('/^A_[A-Z0-9]{16}_C_/', basename($this->fileName))) {
            //     Log::info('AWIPSProduct: Could not determine AWIPS ID. (' . $this->fileName . ')');
            // }
            $prodInfo['awipsId'] = '------';
        }
        // Obtain product type
        if ($prodInfo['awipsId'] !== '------') {
            $prodInfo['productType'] = strtoupper(substr($prodInfo['awipsId'], 0, 3));
        } else {
            $prodInfo['productType'] = 'n/a';
        }
        // Build zoneHeaderList
        $zoneHeaders = [];
        if ($prodInfo['zoneClass'] !== 'A') {
            foreach ($prodInfo['zoneInfo'] as $zoneKey => $zoneData) {
                $tmpHash = [];
                $tmpZoneHash = [];
                if ($zoneKey === 'globalInfo') {
                    continue;
                }
                $zoneInfo = $this->splitZoneInfo($zoneKey, $prodInfo['zoneClass']);
                for ($i = 0; $i < count($zoneInfo['affectedList']); $i++) {
                    $tmpKey = $zoneInfo['affectedList'][$i];
                    $tmpArray = $this->getCountyZoneName($tmpKey);
                    if (! is_null($tmpArray)) {
                        $tmpZoneHash[$tmpKey] = $tmpArray;
                    }
                }
                $tmpHash['zoneList'] = $tmpZoneHash;
                $tmpHash['purgeTime'] = $zoneInfo['purgeTime'];
                $tmpHash['vtecList'] = $this->getVtecList($zoneData);
                $prodContent = preg_split('/\n/', $zoneData);
                $tmpHash['fullTitles'] = $this->getFullTitles($prodInfo, $zoneKey);
                $tmpHash['content'] = $zoneData;
                $tmpHash['globalInfo'] = $prodInfo['globalInfo'];
                array_push($zoneHeaders, $tmpHash);
            }
        }
        $prodInfo['zoneHeaderList'] = $zoneHeaders;
        // If product is a Short-Fuse Warning, get the coordinates of the warning polygon
        if (preg_match('/-(ffs|ffw|mws|smw|svr|svs|tor|sps)/', $prodInfo['productType'])) {
            $prodInfo['affectedAreaCoords'] = $this->getAffectedAreaCoords();
        }

        // Return the $prodInfo array
        return $prodInfo;
    }

    /**
     * Parses the zone header data and splits it into separate zones
     *
     * @param  string  $zoneLine  The zone header
     * @param  string  $zoneType  The zone class (A, B, or C)
     * @return array|null The Zone data
     *
     * array contents:
     *
     * 'zone list'     ref to anon array of affected zones
     * 'purge time'    product purge time in format DDTTTT  (time format is GMT)
     */
    private function splitZoneInfo($zoneLine, $zoneClass): ?array
    {
        $purgeTime = '';
        $zones = [];
        $state = '';
        $zoneInfo = [];
        // print "**DEBUG** \$zoneLine = $zoneLine\n";
        // print "**DEBUG** \$zoneType = $zoneType\n";
        // Make sure zone line has correct format
        if (preg_match('/^\d+$/', $zoneLine)) {
            return null;
        }
        // Make sure product has the right zone class
        if ($zoneClass !== 'B' && $zoneClass !== 'C') {
            throw new \Exception('Invalid product zone class!');

            return null;
        }
        // Correct typos
        $zoneLine = preg_replace('/->/', '>', $zoneLine);
        $zoneLine = preg_replace('/B>/', '>', $zoneLine);
        $zoneLine = preg_replace('/WV001/', 'WVZ001', $zoneLine);
        $zoneLine = preg_replace('/ORZ41/', 'ORZ041', $zoneLine);
        // $zone_line = preg_replace('/^OARZ/', 'OKZ', $zone_line);
        // Fixed botched purgeTime for certain Oregon zone products
        if (preg_match('/^ORZ001>014-021>028/', $zoneLine)) {
            $zoneLine = 'ORZ001>014-021>028-010000-';
        }
        if (preg_match('/(.*)(-|>)(\d{3})(\d{6})-(\s*)$/', $zoneLine, $matches)) {
            $zoneLine = $matches[1].$matches[2].$matches[3].'-'.$matches[4].'-';
        }
        if (preg_match('/\<UGC\+(\d+)\>/', $zoneLine, $matches)) {
            // <UGC+1440>
            $tmpHour = $matches[1];
            $tmpPurgeTimeDay = gmdate('d');
            if ($tmpPurgeTimeDay < 10) {
                $tmpPurgeTimeDay = '0'.$tmpPurgeTimeDay;
            }
            $tmpPurgeTime = $tmpPurgeTimeDay.$tmpHour;
            $zoneLine = preg_replace('/\<UGC\+(\d+)>/', $tmpPurgeTime, $zoneLine);
        }
        $endlessLoopMax = 300;
        // print "**DEBUG** \$zone_line = $zoneLine\n";
        $zoneParts = preg_split('/-/', $zoneLine);
        // Loop through the zone header and parse out the specific zones
        // print "**DEBUG** Number of lines in \$zoneParts = " . count($zoneParts) . "\n";
        // var_dump($zoneParts);
        for ($i = 0; $i < count($zoneParts); $i++) {
            if (preg_match('/^\s*$/', $zoneParts[$i])) {
                continue;
            }
            // print "**DEBUG** Processing \$zoneParts[$i] (" . $zoneParts[$i] . ")..\n";
            if (preg_match('/(\w)(\w)(C|Z)(\d){3}/', $zoneParts[$i], $matches)) {
                $state = $matches[1].$matches[2].$matches[3];
            }
            if (preg_match('/>/', $zoneParts[$i])) {
                // print "**DEBUG** Found continuation delimiter..\n";
                [$firstZone, $secondZone] = preg_split('/>/', $zoneParts[$i]);
                if (preg_match('/^(\w{3})(\d+)/', $firstZone, $matches)) {
                    $firstZone = $matches[2];
                }
                $firstZone = intval($firstZone);
                $secondZone = intval($secondZone);
                if (strlen(strval($firstZone)) > 6) {
                    $firstZone = substr(strval($firstZone), 0, 6);
                }
                if (strlen(strval($secondZone)) > 6) {
                    $secondZone = substr(strval($secondZone), 0, 6);
                }
                // print "**DEBUG** \$firstZone = $firstZone\n";
                // print "**DEBUG** \$secondZone = $secondZone\n";
                if ($firstZone < $secondZone) {
                    $c = 0;
                    for ($j = $firstZone; $j <= $secondZone; $j++) {
                        $zone = $j;
                        if (strlen($zone) === 2) {
                            $zone = '0'.$zone;
                        }
                        if (strlen($zone) === 1) {
                            $zone = '00'.$zone;
                        }
                        if (preg_match('/^\d/', $zone)) {
                            $zone = $state.$zone;
                        }
                        if (strlen($zone) > 6) {
                            $zone = substr($zone, 0, 6);
                        }
                        // print "**DEBUG** \$zone = $zone\n";
                        if (! in_array($zone, $zones)) {
                            array_push($zones, $zone);
                        }
                        if ($c >= 300) {
                            break;
                        }
                        $c++;
                    }
                } else {
                    // print "**DEBUG** Error: \$secondzone is smaller than \$firstzone\n";
                }
            } else {
                if (preg_match('/(\d{6})$/', $zoneParts[$i], $matches)) {
                    $purgeTime = $matches[1];
                    // print "**DEBUG** Got purge time: $purgeTime\n";
                } else {
                    // Add the state to the beginning of the zone if it is not already listed
                    if (preg_match('/^\d/', $zoneParts[$i])) {
                        $zoneParts[$i] = $state.$zoneParts[$i];
                    }
                    // Check for entire state zone specification
                    if (preg_match('/(\w{2})Z000/', $zoneParts[$i], $matches)) {
                        $stateAbbr = $matches[1];
                        $zonesResult = Zone::where('state', '=', $stateAbbr)->get();
                        for ($k = 0; $k < count($zonesResult); $k++) {
                            // print "**DEBUG** zone = " . $zonesResult[$k]->state . 'Z' . $zonesResult[$k]->zone . "\n";
                            if (! isset($zonesResult[$k]->state)) {
                                Log::error("AWIPSProduct: Could not obtain info for \$stateAbbr = $stateAbbr. (".$this->fileName.')');

                                continue;
                            }
                            $zone = $zonesResult[$k]->state.'Z'.$zonesResult[$k]->zone;
                            if (! in_array($zone, $zones)) {
                                array_push($zones, $zone);
                            }
                        }
                    } else {
                        if (strlen($zoneParts[$i]) > 6) {
                            $zoneParts[$i] = substr($zoneParts[$i], 0, 6);
                        }
                        // print "**DEBUG** zone = " . $zoneParts[$i] . "\n";
                        if (! in_array($zoneParts[$i], $zones)) {
                            array_push($zones, $zoneParts[$i]);
                        }
                    }
                }
            }
            // If the zone header is botched, avoid endless loop (and overall crash)
            if ($i >= $endlessLoopMax) {
                Log::notice('AWIPSProduct: zone header is malformed, breaking out of endless loop to avoid hang.');
                break;
            }
        }
        if ($purgeTime === '') {
            // print "**DEBUG** \$purgeTime is empty, building..\n";
            $newPurgeTime = localtime(time() + 60 * 60 * 4);		// default to 4 hour purge time
            $newPurgeTimeMin = $newPurgeTime[1];
            $newPurgeTimeHour = $newPurgeTime[2];
            $newPurgeTimeDay = $newPurgeTime[3];
            if ($newPurgeTimeMin < 10) {
                $newPurgeTimeMin = '0'.$newPurgeTimeMin;
            }
            if ($newPurgeTimeHour < 10) {
                $newPurgeTimeHour = '0'.$newPurgeTimeHour;
            }
            if ($newPurgeTimeDay < 10) {
                $newPurgeTimeDay = '0'.$newPurgeTimeDay;
            }
            $purgeTime = $newPurgeTimeDay.$newPurgeTimeHour.$newPurgeTimeMin;
        }
        // print "**DEBUG** \$purgeTime = $purgeTime\n";
        // sort($zones);
        // print "**DEBUG** \$zones = " . implode(", ", $zones) . "\n\n";
        // Store the product's purge time and affected list in the hash
        $zoneInfo['purgeTime'] = $this->buildFullGmtTime($purgeTime);
        $zoneInfo['affectedList'] = $zones;

        // Return the $zoneInfo array
        return $zoneInfo;
    }

    /**
     * Obtain a SEL's purge time
     *
     * @return int The SEL's purge time
     */
    private function getProdSelPurgeTime(): int
    {
        $purgeTime = 0;
        for ($i = 0; $i < count($this->fileContents); $i++) {
            if (preg_match('/\-(\d{6})\-\s*$/', $this->fileContents[$i], $matches)) {
                $purgeTime = $matches[1];
                break;
            }
        }

        return $this->buildFullGmtTime($purgeTime);
    }

    /**
     * Gets a product's fullname from vtec_products or text_products
     *
     * @param  string  $abbr  The 3-character abbreviation
     * @return string The product's full name
     */
    private function getFullNameFromAbbr($abbr): string
    {
        try {
            $vtecProduct = VtecProduct::where('abbr', '=', $abbr)->firstOrFail();

            return $vtecProduct->fullname;
        } catch (ModelNotFoundException $ex1) {
            try {
                $textProduct = TextProduct::where('abbr', '=', $abbr)->firstOrFail();

                return $textProduct->fullname;
            } catch (ModelNotFoundException $ex2) {
                return '??';
            }
        }
    }

    /**
     * Gets information about a Short-Fush Warning
     *
     * @return array The short-fuse warning's metadata
     */
    private function getSfwInfo(): ?array
    {
        $sfwWarningTypes = [
            'TOR' => 'Tornado Warning',
            'SVR' => 'Severe Thunderstorm Warning',
            'FFW' => 'Flash Flood Warning',
            'DSW' => 'Dust Storm Warning',
            'SQW' => 'Snow Squal Warning',
            'EWW' => 'Extreme Wind Warning',
            'SVS' => 'Severe Weather Statement',
            'FFS' => 'Flash Flood Statement',
        ];
        // Make sure the product is a Short-Fuse Warning
        if (! preg_match('/^(TOR|SVR|SVS|FFW|FFS|DSW|SQW|EWW)$/', $this->getProductType())) {
            // throw new \Exception('Product is not a Short-Fuse Warning!');
            return null;
        }
        $sfwInfo = [
            'type' => $sfwWarningTypes[$this->getProductType()],
            'for' => [],
            'at' => '',
            'impact' => '',
            'locations' => '',
            'actions' => [],
            'footer' => [
                'latLon' => '',
                'timeMotLoc' => '',
                'tornado' => '',
                'tornadoDmgThreat' => '',
                'tstormDmgThreat' => '',
                'hailThreat' => '',
                'hail' => '',
                'windThreat' => '',
                'wind' => '',
                'flashFlood' => '',
            ],
        ];
        $warningForFlag = false;
        $atFlag = false;
        $impactFlag = false;
        $nearFlag = false;
        $actionsFlag = false;
        $footerFlag = false;
        $latLonFlag = false;
        $tmpActions = '';
        for ($i = 0; $i < count($this->fileContents); $i++) {
            if (preg_match('/\* (.*) Warning for\.\.\./i', $this->fileContents[$i], $matches)) {
                $warningForFlag = true;
                $i++;
            }
            if ($warningForFlag && preg_match('/^\s*$/', $this->fileContents[$i])) {
                $warningForFlag = false;
            }
            if ($warningForFlag) {
                array_push($sfwInfo['for'], trim(str_replace('...', '', $this->fileContents[$i])));
            }
            if (preg_match('/\* Until (.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['until'] = $matches[1];
            }
            if (preg_match('/\* At /i', $this->fileContents[$i], $matches)) {
                $sfwInfo['at'] .= trim(str_replace('* ', '', $this->fileContents[$i])).' ';
                $atFlag = true;
                $i++;
            }
            if ($atFlag && preg_match('/^\s*$/', $this->fileContents[$i])) {
                $atFlag = false;
            }
            if ($atFlag) {
                $sfwInfo['at'] .= trim($this->fileContents[$i]).' ';
            }
            if (preg_match('/HAZARD\.\.\.(.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['hazard'] = rtrim($matches[1]);
            }
            if (preg_match('/SOURCE\.\.\.(.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['source'] = rtrim($matches[1]);
            }
            if (preg_match('/IMPACT\.\.\.(.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['impact'] .= rtrim($matches[1]).' ';
                $impactFlag = true;
                $i++;
            }
            if ($impactFlag && preg_match('/^\s*$/', $this->fileContents[$i])) {
                $impactFlag = false;
            }
            if ($impactFlag) {
                $sfwInfo['impact'] .= trim($this->fileContents[$i]).' ';
            }
            if (preg_match('/\* This (.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['locations'] .= str_replace('* ', '', $this->fileContents[$i]).' ';
                $nearFlag = true;
                $i++;
            }
            if ($nearFlag && preg_match('/^\s*$/', $this->fileContents[$i])) {
                $nearFlag = false;
            }
            if ($nearFlag) {
                $sfwInfo['locations'] .= trim($this->fileContents[$i]).' ';
            }
            if (preg_match('/\* Locations impacted include\.\.\./i', $this->fileContents[$i], $matches)) {
                $sfwInfo['locations'] .= str_replace('* ', '', $this->fileContents[$i]);
                $nearFlag = true;
                $i++;
            }
            if ($nearFlag && preg_match('/^\s*$/', $this->fileContents[$i])) {
                $nearFlag = false;
            }
            if ($nearFlag) {
                $sfwInfo['locations'] .= trim($this->fileContents[$i]).' ';
            }
            if (preg_match('/PRECAUTIONARY\/PREPAREDNESS ACTIONS\.\.\./i', $this->fileContents[$i])) {
                $actionsFlag = true;
                $i += 2;
            }
            if ($actionsFlag && preg_match('/^\&\&/', $this->fileContents[$i])) {
                $actionsFlag = false;
                $footerFlag = true;
            }
            if ($actionsFlag) {
                if (preg_match('/^\s*$/', $this->fileContents[$i])) {
                    array_push($sfwInfo['actions'], rtrim($tmpActions));
                    $tmpActions = '';
                } else {
                    $tmpActions .= $this->fileContents[$i].' ';
                }
            }
            if ($footerFlag && preg_match('/^LAT\.\.\.LON (.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['footer']['latLon'] .= rtrim($matches[1]).' ';
                $latLonFlag = true;
                $i++;
            }
            if ($latLonFlag) {
                if (preg_match('/^TIME\.\.\.MOT\.\.\.LOC/', $this->fileContents[$i])) {
                    $latLonFlag = false;
                } else {
                    $sfwInfo['footer']['latLon'] .= trim($this->fileContents[$i]);
                }
            }
            if ($footerFlag && preg_match('/^TIME\.\.\.MOT\.\.\.LOC (.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['footer']['timeMotLoc'] .= $matches[1];
            }
            if ($footerFlag && preg_match('/^TORNADO\.\.\.(.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['footer']['tornado'] .= trim($matches[1]);
            }
            if ($footerFlag && preg_match('/^TORNADO DAMAGE THREAT\.\.\.(.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['footer']['tornadoDmgThreat'] .= trim($matches[1]);
            }
            if ($footerFlag && preg_match('/^THUNDERSTORM DAMAGE THREAT\.\.\.(.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['footer']['tstormDmgThreat'] .= trim($matches[1]);
            }
            if ($footerFlag && preg_match('/^HAIL THREAT\.\.\.(.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['footer']['hailThreat'] .= trim($matches[1]);
            }
            if ($footerFlag && preg_match('/^(HAIL MAX|MAX HAIL) SIZE\.\.\.(.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['footer']['hail'] .= trim($matches[2]);
            }
            if ($footerFlag && preg_match('/^WIND THREAT\.\.\.(.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['footer']['windThreat'] .= trim($matches[1]);
            }
            if ($footerFlag && preg_match('/^MAX WIND GUST\.\.\.(.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['footer']['wind'] .= trim($matches[1]);
            }
            if ($footerFlag && preg_match('/^FLASH FLOOD\.\.\.(.*)/i', $this->fileContents[$i], $matches)) {
                $sfwInfo['footer']['flashFlood'] .= trim($matches[1]);
            }
        }
        $sfwInfo['impact'] = rtrim(str_replace('...', ', ', $sfwInfo['impact']));
        $sfwInfo['locations'] = rtrim(str_replace('...', ' ', $sfwInfo['locations']));

        return $sfwInfo;
    }
}
