<?php

/**
 * Class for reading datafiles generated by Webgrind_Preprocessor
 *
 * @package Webgrind
 * @author Jacob Oettinger
 */
class Webgrind_Reader
{
    /**
     * File format version that this reader understands
     */
    const FILE_FORMAT_VERSION = 7;

    /**
     * Binary number format used.
     * @see http://php.net/pack
     */
    const NR_FORMAT = 'V';

    /**
     * Size, in bytes, of the above number format
     */
    const NR_SIZE = 4;

    /**
     * Length of a call information block
     */
    const CALLINFORMATION_LENGTH = 4;

    /**
     * Length of a function information block
     */
    const FUNCTIONINFORMATION_LENGTH = 6;

    /**
     * Address of the headers in the data file
     *
     * @var int
     */
    private $headersPos;

    /**
     * Array of addresses pointing to information about functions
     *
     * @var array
     */
    private $functionPos;

    /**
     * Array of headers
     *
     * @var array
     */
    private $headers = null;

    /**
     * Format to return costs in
     *
     * @var string
     */
    private $costFormat;


    /**
     * Constructor
     * @param string Data file to read
     * @param string Format to return costs in
     */
    function __construct($dataFile, $costFormat)
    {
        $this->fp = @fopen($dataFile, 'rb');
        if (!$this->fp)
            throw new Exception('Error opening file!');

        $this->costFormat = $costFormat;
        $this->init();
    }

    /**
     * Initializes the parser by reading initial information.
     *
     * Throws an exception if the file version does not match the readers version
     *
     * @return void
     * @throws Exception
     */
    private function init()
    {
        list($version, $this->headersPos, $functionCount) = $this->read(3);
        if ($version != self::FILE_FORMAT_VERSION)
            throw new Exception('Datafile not correct version. Found ' . $version . ' expected ' . self::FILE_FORMAT_VERSION);
        $this->functionPos = $this->read($functionCount);
        if (!is_array($this->functionPos))
            $this->functionPos = [$this->functionPos];
    }

    /**
     * Returns number of functions
     * @return int
     */
    function getFunctionCount()
    {
        return count($this->functionPos);
    }

    /**
     * Returns information about function with nr $nr
     *
     * @param $nr int Function number
     * @return array Function information
     */
    function getFunctionInfo($nr)
    {
        $this->seek($this->functionPos[$nr]);

        list($line, $summedSelfCost, $summedInclusiveCost, $invocationCount, $calledFromCount, $subCallCount) = $this->read(self::FUNCTIONINFORMATION_LENGTH);

        $this->seek(self::NR_SIZE * self::CALLINFORMATION_LENGTH * ($calledFromCount + $subCallCount), SEEK_CUR);
        $file = $this->readLine();
        $function = $this->readLine();

        $result = array(
            'file' => $file,
            'line' => $line,
            'functionName' => $function,
            'summedSelfCost' => $summedSelfCost,
            'summedInclusiveCost' => $summedInclusiveCost,
            'invocationCount' => $invocationCount,
            'calledFromInfoCount' => $calledFromCount,
            'subCallInfoCount' => $subCallCount
        );
        $result['summedSelfCostRaw'] = $result['summedSelfCost'];
        $result['summedSelfCost'] = $this->formatCost($result['summedSelfCost']);
        $result['summedInclusiveCost'] = $this->formatCost($result['summedInclusiveCost']);

        return $result;
    }

    /**
     * Returns information about positions where a function has been called from
     *
     * @param $functionNr int Function number
     * @param $calledFromNr int Called from position nr
     * @return array Called from information
     */
    function getCalledFromInfo($functionNr, $calledFromNr)
    {
        $this->seek(
            $this->functionPos[$functionNr]
            + self::NR_SIZE
            * (self::CALLINFORMATION_LENGTH * $calledFromNr + self::FUNCTIONINFORMATION_LENGTH)
        );

        $data = $this->read(self::CALLINFORMATION_LENGTH);

        $result = array(
            'functionNr' => $data[0],
            'line' => $data[1],
            'callCount' => $data[2],
            'summedCallCost' => $data[3]
        );

        $result['summedCallCost'] = $this->formatCost($result['summedCallCost']);

        return $result;
    }

    /**
     * Returns information about functions called by a function
     *
     * @param $functionNr int Function number
     * @param $subCallNr int Sub call position nr
     * @return array Sub call information
     */
    function getSubCallInfo($functionNr, $subCallNr)
    {
        // Sub call count is the second last number in the FUNCTION_INFORMATION block
        $this->seek($this->functionPos[$functionNr] + self::NR_SIZE * (self::FUNCTIONINFORMATION_LENGTH - 2));
        $calledFromInfoCount = $this->read();
        $this->seek((($calledFromInfoCount + $subCallNr) * self::CALLINFORMATION_LENGTH + 1) * self::NR_SIZE, SEEK_CUR);
        $data = $this->read(self::CALLINFORMATION_LENGTH);

        $result = array(
            'functionNr' => $data[0],
            'line' => $data[1],
            'callCount' => $data[2],
            'summedCallCost' => $data[3]
        );

        $result['summedCallCost'] = $this->formatCost($result['summedCallCost']);

        return $result;
    }

    /**
     * Returns value of a single header
     *
     * @return string Header value
     */
    function getHeader($header)
    {
        if ($this->headers == null) { // Cache headers
            $this->seek($this->headersPos);
            $this->headers = array(
                'runs' => 0,
                'summary' => 0,
                'cmd' => '',
                'creator' => '',
            );
            while ($line = $this->readLine()) {
                $parts = explode(': ', $line);
                if ($parts[0] == 'summary') {
                    // According to https://github.com/xdebug/xdebug/commit/926808a6e0204f5835a617caa3581b45f6d82a6c#diff-1a570e993c4d7f2e341ba24905b8b2cdR355
                    // summary now includes time + memory usage, webgrind only tracks the time from the summary
                    $subParts = explode(' ', $parts[1]);
                    $this->headers['runs']++;
                    $this->headers['summary'] += $subParts[0];
                } else {
                    $this->headers[$parts[0]] = $parts[1];
                }
            }
        }

        return $this->headers[$header];
    }

    /**
     * Formats $cost using the format in $this->costFormat or optionally the format given as input
     *
     * @param int $cost Cost
     * @param string $format 'percent', 'msec' or 'usec'
     * @return int Formatted cost
     */
    function formatCost($cost, $format = null)
    {
        if ($format == null)
            $format = $this->costFormat;

        if ($format == 'percent') {
            $total = $this->getHeader('summary');
            $result = ($total == 0) ? 0 : ($cost * 100) / $total;
            return number_format($result, 2, '.', '');
        }

        if ($format == 'msec') {
            return round($cost / 1000, 0);
        }

        // Default usec
        return $cost;
    }

    private function read($numbers = 1)
    {
        $values = unpack(self::NR_FORMAT . $numbers, fread($this->fp, self::NR_SIZE * $numbers));
        if ($numbers == 1)
            return $values[1];
        else
            return array_values($values); // reindex and return
    }

    private function readLine()
    {
        $result = fgets($this->fp);
        if ($result)
            return trim($result);
        else
            return $result;
    }

    private function seek($offset, $whence = SEEK_SET)
    {
        return fseek($this->fp, $offset, $whence);
    }

}

/**
 * Class for preprocessing callgrind files.
 *
 * Information from the callgrind file is extracted and written in a binary format for
 * fast random access.
 *
 * @see https://github.com/jokkedk/webgrind/wiki/Preprocessed-Format
 * @see http://valgrind.org/docs/manual/cl-format.html
 * @package Webgrind
 * @author Jacob Oettinger
 */
class Webgrind_Preprocessor
{

    /**
     * Fileformat version. Embedded in the output for parsers to use.
     */
    const FILE_FORMAT_VERSION = 7;

    /**
     * Binary number format used.
     * @see http://php.net/pack
     */
    const NR_FORMAT = 'V';

    /**
     * Size, in bytes, of the above number format
     */
    const NR_SIZE = 4;

    /**
     * String name of main function
     */
    const ENTRY_POINT = '{main}';


    /**
     * Extract information from $inFile and store in preprocessed form in $outFile
     *
     * @param string $inFile Callgrind file to read
     * @param string $outFile File to write preprocessed data to
     * @return void
     */
    static function parse($inFile, $outFile)
    {
        // If possible, use the binary preprocessor
        if (self::binaryParse($inFile, $outFile)) {
            return;
        }

        $in = @fopen($inFile, 'rb');
        if (!$in)
            throw new Exception('Could not open ' . $inFile . ' for reading.');
        $out = @fopen($outFile, 'w+b');
        if (!$out)
            throw new Exception('Could not open ' . $outFile . ' for writing.');

        $proxyFunctions = array_flip(Webgrind_Config::$proxyFunctions);
        $proxyQueue = array();
        $nextFuncNr = 0;
        $functionNames = array();
        $functions = array();
        $headers = array();

        // Read information into memory
        while (($line = fgets($in))) {
            if (substr($line, 0, 3) === 'fl=') {
                // Found invocation of function. Read function name
                fscanf($in, "fn=%[^\n\r]s", $function);
                $function = self::getCompressedName($function, false);
                // Special case for ENTRY_POINT - it contains summary header
                if (self::ENTRY_POINT == $function) {
                    fgets($in);
                    $headers[] = fgets($in);
                    fgets($in);
                }
                // Cost line
                fscanf($in, "%d %d", $lnr, $cost);

                if (!isset($functionNames[$function])) {
                    $index = $nextFuncNr++;
                    $functionNames[$function] = $index;
                    if (isset($proxyFunctions[$function])) {
                        $proxyQueue[$index] = array();
                    }
                    $functions[$index] = array(
                        'filename' => self::getCompressedName(substr(trim($line), 3), true),
                        'line' => $lnr,
                        'invocationCount' => 1,
                        'summedSelfCost' => $cost,
                        'summedInclusiveCost' => $cost,
                        'calledFromInformation' => array(),
                        'subCallInformation' => array()
                    );
                } else {
                    $index = $functionNames[$function];
                    $functions[$index]['invocationCount']++;
                    $functions[$index]['summedSelfCost'] += $cost;
                    $functions[$index]['summedInclusiveCost'] += $cost;
                }
            } else if (substr($line, 0, 4) === 'cfn=') {
                // Found call to function. ($function/$index should contain function call originates from)
                $calledFunctionName = self::getCompressedName(substr(trim($line), 4), false);
                // Skip call line
                fgets($in);
                // Cost line
                fscanf($in, "%d %d", $lnr, $cost);

                // Current function is a proxy -> skip
                if (isset($proxyQueue[$index])) {
                    $proxyQueue[$index][] = array(
                        'calledIndex' => $functionNames[$calledFunctionName],
                        'lnr' => $lnr,
                        'cost' => $cost,
                    );
                    continue;
                }

                $calledIndex = $functionNames[$calledFunctionName];
                // Called a proxy
                if (isset($proxyQueue[$calledIndex])) {
                    $data = array_shift($proxyQueue[$calledIndex]);
                    $calledIndex = $data['calledIndex'];
                    $lnr = $data['lnr'];
                    $cost = $data['cost'];
                }

                $functions[$index]['summedInclusiveCost'] += $cost;

                $key = $index . $lnr;
                if (!isset($functions[$calledIndex]['calledFromInformation'][$key])) {
                    $functions[$calledIndex]['calledFromInformation'][$key] = array('functionNr' => $index, 'line' => $lnr, 'callCount' => 0, 'summedCallCost' => 0);
                }

                $functions[$calledIndex]['calledFromInformation'][$key]['callCount']++;
                $functions[$calledIndex]['calledFromInformation'][$key]['summedCallCost'] += $cost;

                $calledKey = $calledIndex . $lnr;
                if (!isset($functions[$index]['subCallInformation'][$calledKey])) {
                    $functions[$index]['subCallInformation'][$calledKey] = array('functionNr' => $calledIndex, 'line' => $lnr, 'callCount' => 0, 'summedCallCost' => 0);
                }

                $functions[$index]['subCallInformation'][$calledKey]['callCount']++;
                $functions[$index]['subCallInformation'][$calledKey]['summedCallCost'] += $cost;

            } else if (strpos($line, ': ') !== false) {
                // Found header
                $headers[] = $line;
            }
        }

        $functionNames = array_flip($functionNames);

        // Write output
        $functionCount = sizeof($functions);
        fwrite($out, pack(self::NR_FORMAT . '*', self::FILE_FORMAT_VERSION, 0, $functionCount));
        // Make room for function addresses
        fseek($out, self::NR_SIZE * $functionCount, SEEK_CUR);
        $functionAddresses = array();
        foreach ($functions as $index => $function) {
            $functionAddresses[] = ftell($out);
            $calledFromCount = sizeof($function['calledFromInformation']);
            $subCallCount = sizeof($function['subCallInformation']);
            fwrite($out, pack(self::NR_FORMAT . '*', $function['line'], $function['summedSelfCost'], $function['summedInclusiveCost'], $function['invocationCount'], $calledFromCount, $subCallCount));
            // Write called from information
            foreach ((array)$function['calledFromInformation'] as $call) {
                fwrite($out, pack(self::NR_FORMAT . '*', $call['functionNr'], $call['line'], $call['callCount'], $call['summedCallCost']));
            }
            // Write sub call information
            foreach ((array)$function['subCallInformation'] as $call) {
                fwrite($out, pack(self::NR_FORMAT . '*', $call['functionNr'], $call['line'], $call['callCount'], $call['summedCallCost']));
            }

            fwrite($out, $function['filename'] . "\n" . $functionNames[$index] . "\n");
        }
        $headersPos = ftell($out);
        // Write headers
        foreach ($headers as $header) {
            fwrite($out, $header);
        }

        // Write addresses
        fseek($out, self::NR_SIZE, SEEK_SET);
        fwrite($out, pack(self::NR_FORMAT, $headersPos));
        // Skip function count
        fseek($out, self::NR_SIZE, SEEK_CUR);
        // Write function addresses
        foreach ($functionAddresses as $address) {
            fwrite($out, pack(self::NR_FORMAT, $address));
        }

        fclose($in);
        fclose($out);
    }

    /**
     * Extract information from $inFile and store in preprocessed form in $outFile
     *
     * @param string $name String to parse (either a filename or function name line)
     * @param int $isFile True if this is a filename line (since files and functions have their own symbol tables)
     * @return void
     **/
    static function getCompressedName($name, $isFile)
    {
        global $compressedNames;
        if (!preg_match("/\((\d+)\)(.+)?/", $name, $matches)) {
            return $name;
        }
        $functionIndex = $matches[1];
        if (isset($matches[2])) {
            $compressedNames[$isFile][$functionIndex] = trim($matches[2]);
        } else if (!isset($compressedNames[$isFile][$functionIndex])) {
            return $name; // should not happen - is file valid?
        }
        return $compressedNames[$isFile][$functionIndex];
    }

    /**
     * Extract information from $inFile and store in preprocessed form in $outFile
     * using the (~20x) faster binary preprocessor
     *
     * @param string $inFile Callgrind file to read
     * @param string $outFile File to write preprocessed data to
     * @return bool True if binary preprocessor was executed
     */
    static function binaryParse($inFile, $outFile)
    {
        $preprocessor = Webgrind_Config::getBinaryPreprocessor();
        if (!is_executable($preprocessor)) {
            return false;
        }

        $cmd = escapeshellarg($preprocessor) . ' ' . escapeshellarg($inFile) . ' ' . escapeshellarg($outFile);
        foreach (Webgrind_Config::$proxyFunctions as $function) {
            $cmd .= ' ' . escapeshellarg($function);
        }
        exec($cmd, $output, $ret);
        return $ret == 0;
    }

}

/**
 * Class handling access to data-files(original and preprocessed) for webgrind.
 * @author Jacob Oettinger
 * @author Joakim Nygård
 */
class Webgrind_FileHandler
{

    private static $singleton = null;


    /**
     * @return Webgrind_FileHandler singleton instance of the filehandler
     */
    public static function getInstance()
    {
        if (self::$singleton == null)
            self::$singleton = new self();
        return self::$singleton;
    }

    private function __construct()
    {
        // Get list of files matching the defined format
        $files = $this->getFiles(Webgrind_Config::xdebugOutputFormat(), Webgrind_Config::xdebugOutputDir());

        // Get list of preprocessed files
        $prepFiles = $this->getPrepFiles('/\\' . Webgrind_Config::$preprocessedSuffix . '$/', Webgrind_Config::storageDir());
        // Loop over the preprocessed files.
        foreach ($prepFiles as $fileName => $prepFile) {
            $fileName = str_replace(Webgrind_Config::$preprocessedSuffix, '', $fileName);

            // If it is older than its corrosponding original: delete it.
            // If it's original does not exist: delete it
            if (!isset($files[$fileName]) || $files[$fileName]['mtime'] > $prepFile['mtime'])
                unlink($prepFile['absoluteFilename']);
            else
                $files[$fileName]['preprocessed'] = true;
        }
        // Sort by mtime
        uasort($files, array($this, 'mtimeCmp'));

        $this->files = $files;
    }

    /**
     * Get the value of the cmd header in $file
     *
     * @return void string
     */
    private function getInvokeUrl($file)
    {
        if (preg_match('/\.webgrind$/', $file))
            return 'Webgrind internal';

        // Grab name of invoked file.
        $fp = fopen($file, 'r');
        $invokeUrl = '';
        while ((($line = fgets($fp)) !== FALSE) && !strlen($invokeUrl)) {
            if (preg_match('/^cmd: (.*)$/', $line, $parts)) {
                $invokeUrl = isset($parts[1]) ? $parts[1] : '';
            }
        }
        fclose($fp);
        if (!strlen($invokeUrl))
            $invokeUrl = 'Unknown!';

        return $invokeUrl;
    }

    /**
     * List of files in $dir whose filename has the format $format
     *
     * @return array Files
     */
    private function getFiles($format, $dir)
    {
        $list = preg_grep($format, scandir($dir));
        $files = array();

        # Moved this out of loop to run faster
        if (function_exists('xdebug_get_profiler_filename'))
            $selfFile = realpath(xdebug_get_profiler_filename());
        else
            $selfFile = '';

        foreach ($list as $file) {
            $absoluteFilename = $dir . $file;

            // Exclude webgrind preprocessed files
            if (false !== strstr($absoluteFilename, Webgrind_Config::$preprocessedSuffix))
                continue;

            // Make sure that script never parses the profile currently being generated. (infinite loop)
            if ($selfFile == realpath($absoluteFilename))
                continue;

            $invokeUrl = rtrim($this->getInvokeUrl($absoluteFilename));
            if (Webgrind_Config::$hideWebgrindProfiles && $invokeUrl == dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'index.php')
                continue;

            $files[$file] = array('absoluteFilename' => $absoluteFilename,
                'mtime' => filemtime($absoluteFilename),
                'preprocessed' => false,
                'invokeUrl' => $invokeUrl,
                'filesize' => $this->bytestostring(filesize($absoluteFilename))
            );
        }
        return $files;
    }

    /**
     * List of files in $dir whose filename has the format $format
     *
     * @return array Files
     */
    private function getPrepFiles($format, $dir)
    {
        $list = preg_grep($format, scandir($dir));
        $files = array();

        foreach ($list as $file) {
            $absoluteFilename = $dir . $file;

            // Make sure that script does not include the profile currently being generated. (infinite loop)
            if (function_exists('xdebug_get_profiler_filename') && realpath(xdebug_get_profiler_filename()) == realpath($absoluteFilename))
                continue;

            $files[$file] = array('absoluteFilename' => $absoluteFilename,
                'mtime' => filemtime($absoluteFilename),
                'preprocessed' => true,
                'filesize' => $this->bytestostring(filesize($absoluteFilename))
            );
        }
        return $files;
    }

    /**
     * Get list of available trace files. Optionally including traces of the webgrind script it self
     *
     * @return array Files
     */
    public function getTraceList()
    {
        $result = array();
        foreach ($this->files as $fileName => $file) {
            $result[] = array('filename' => $fileName,
                'invokeUrl' => str_replace($_SERVER['DOCUMENT_ROOT'] . '/', '', $file['invokeUrl']),
                'filesize' => $file['filesize'],
                'mtime' => date(Webgrind_Config::$dateFormat, $file['mtime'])
            );
        }
        return $result;
    }

    /**
     * Get a trace reader for the specific file.
     *
     * If the file has not been preprocessed yet this will be done first.
     *
     * @param string File to read
     * @param Cost format for the reader
     * @return Webgrind_Reader Reader for $file
     */
    public function getTraceReader($file, $costFormat)
    {
        $prepFile = Webgrind_Config::storageDir() . $file . Webgrind_Config::$preprocessedSuffix;
        try {
            $r = new Webgrind_Reader($prepFile, $costFormat);
        } catch (Exception $e) {
            // Preprocessed file does not exist or other error
            Webgrind_Preprocessor::parse(Webgrind_Config::xdebugOutputDir() . $file, $prepFile);
            $r = new Webgrind_Reader($prepFile, $costFormat);
        }
        return $r;
    }

    /**
     * Comparison function for sorting
     *
     * @return boolean
     */
    private function mtimeCmp($a, $b)
    {
        if ($a['mtime'] == $b['mtime'])
            return 0;

        return ($a['mtime'] > $b['mtime']) ? -1 : 1;
    }

    /**
     * Present a size (in bytes) as a human-readable value
     *
     * @param int $size size (in bytes)
     * @param int $precision number of digits after the decimal point
     * @return string
     */
    private function bytestostring($size, $precision = 0)
    {
        $sizes = array('YB', 'ZB', 'EB', 'PB', 'TB', 'GB', 'MB', 'KB', 'B');
        $total = count($sizes);

        while ($total-- && $size > 1024) {
            $size /= 1024;
        }
        return round($size, $precision) . $sizes[$total];
    }
}
