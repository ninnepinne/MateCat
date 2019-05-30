<?php

namespace FilesStorage;

/**
 * Class FsFilesStorage
 *
 * INDEX
 * -------------------------------------------------------------------------
 * 1. FILE HANDLING ON FILE SYSTEM
 * 2. CACHE PACKAGE HELPERS
 * 3. PROJECT
 *
 * @package FilesStorage
 */
abstract class AbstractFilesStorage implements IFilesStorage {

    const ORIGINAL_ZIP_PLACEHOLDER = "__##originalZip##";

    protected $filesDir;
    protected $cacheDir;
    protected $zipDir;

    public function __construct( $files = null, $cache = null, $zip = null ) {

        //override default config
        if ( $files ) {
            $this->filesDir = $files;
        } else {
            $this->filesDir = \INIT::$FILES_REPOSITORY;
        }

        if ( $cache ) {
            $this->cacheDir = $cache;
        } else {
            $this->cacheDir = \INIT::$CACHE_REPOSITORY;
        }

        if ( $zip ) {
            $this->zipDir = $zip;
        } else {
            $this->zipDir = \INIT::$ZIP_REPOSITORY;
        }
    }

    /**
     **********************************************************************************************
     * 1. FILE HANDLING ON FILE SYSTEM
     **********************************************************************************************
     */

    /**
     * @param $path
     *
     * @return mixed
     */
    public static function basename_fix( $path ) {
        $rawPath  = explode( DIRECTORY_SEPARATOR, $path );
        $basename = array_pop( $rawPath );

        return $basename;
    }

    /**
     * PHP Pathinfo is not UTF-8 aware, so we rewrite it.
     * It returns array with complete info about a path
     * [
     *    'dirname'   => PATHINFO_DIRNAME,
     *    'basename'  => PATHINFO_BASENAME,
     *    'extension' => PATHINFO_EXTENSION,
     *    'filename'  => PATHINFO_FILENAME
     * ]
     *
     * @param     $path
     * @param int $options
     *
     * @return array|mixed
     */
    public static function pathinfo_fix( $path, $options = 15 ) {
        $rawPath = explode( DIRECTORY_SEPARATOR, $path );

        $basename = array_pop( $rawPath );
        $dirname  = implode( DIRECTORY_SEPARATOR, $rawPath );

        $explodedFileName = explode( ".", $basename );
        $extension        = strtolower( array_pop( $explodedFileName ) );
        $filename         = implode( ".", $explodedFileName );

        $return_array = [];

        $flagMap = [
                'dirname'   => PATHINFO_DIRNAME,
                'basename'  => PATHINFO_BASENAME,
                'extension' => PATHINFO_EXTENSION,
                'filename'  => PATHINFO_FILENAME
        ];

        // foreach flag, add in $return_array the corresponding field,
        // obtained by variable name correspondence
        foreach ( $flagMap as $field => $i ) {
            //binary AND
            if ( ( $options & $i ) > 0 ) {
                //variable substitution: $field can be one between 'dirname', 'basename', 'extension', 'filename'
                // $$field gets the value of the variable named $field
                $return_array[ $field ] = $$field;
            }
        }

        if ( count( $return_array ) == 1 ) {
            $return_array = array_pop( $return_array );
        }

        return $return_array;
    }

    /**
     * @param $path
     *
     * @return bool|string
     */
    public function getSingleFileInPath( $path ) {

        //check if it actually exist
        $filePath = false;
        $files    = [];
        try {
            $files = new \DirectoryIterator( $path );
        } catch ( \Exception $e ) {
            //directory does not exists
            \Log::doJsonLog( "Directory $path does not exists. If you are creating a project check the source language." );
        }

        foreach ( $files as $key => $file ) {

            if ( $file->isDot() ) {
                continue;
            }

            //get the remaining file (it's the only file in dir)
            $filePath = $path . DIRECTORY_SEPARATOR . $file->getFilename();
            //no need to loop anymore
            break;

        }

        return $filePath;
    }

    /**
     * Delete a hash from upload directory
     *
     * @param $uploadDirPath
     * @param $linkFile
     */
    public function deleteHashFromUploadDir( $uploadDirPath, $linkFile ) {
        @list( $shasum, $srcLang ) = explode( "|", $linkFile );

        $iterator = new \DirectoryIterator( $uploadDirPath );

        foreach ( $iterator as $fileInfo ) {
            if ( $fileInfo->isDot() || $fileInfo->isDir() ) {
                continue;
            }

            // remove only the wrong languages, the same code|language must be
            // retained because of the file name append
            if ( $fileInfo->getFilename() != $linkFile &&
                    stripos( $fileInfo->getFilename(), $shasum ) !== false ) {

                unlink( $fileInfo->getPathname() );
                \Log::doJsonLog( "Deleted Hash " . $fileInfo->getPathname() );

            }
        }
    }

    /**
     * @param $create_date
     *
     * @return string
     */
    public function getDatePath( $create_date ) {
        return date_create( $create_date )->format( 'Ymd' );
    }

    /**
     **********************************************************************************************
     * 2. CACHE PACKAGE HELPERS
     **********************************************************************************************
     */

    /**
     * Return an array to build thr cache path from an hash
     *
     * @param $hash
     *
     * @return array
     */
    public static function composeCachePath( $hash ) {

        $cacheTree = [
                'firstLevel'  => $hash{0} . $hash{1},
                'secondLevel' => $hash{2} . $hash{3},
                'thirdLevel'  => substr( $hash, 4 )
        ];

        return $cacheTree;

    }

    /**
     * @param $hash
     * @param $lang
     * @param $uid
     * @param $realFileName
     *
     * @return int
     */
    public function linkSessionToCacheForAlreadyConvertedFiles( $hash, $lang, $uid, $realFileName ) {
        //get upload dir
        $dir = \INIT::$QUEUE_PROJECT_REPOSITORY . DIRECTORY_SEPARATOR . $uid;

        //create a file in it, which is called as the hash that indicates the location of the cache for storage
        return $this->_linkToCache( $dir, $hash, $lang, $realFileName );
    }

    /**
     * @param $hash
     * @param $lang
     * @param $uid
     * @param $realFileName
     *
     * @return int
     */
    public function linkSessionToCacheForOriginalFiles( $hash, $lang, $uid, $realFileName ) {
        //get upload dir
        $dir = \INIT::$UPLOAD_REPOSITORY . DIRECTORY_SEPARATOR . $uid;

        //create a file in it, which is called as the hash that indicates the location of the cache for storage
        return $this->_linkToCache( $dir, $hash, $lang, $realFileName );
    }

    /**
     * Appends a string like $dir . DIRECTORY_SEPARATOR . $hash . "|" . $lang (the path in cache package of file in file storage system)
     * on $realFileName file
     *
     * @param $dir
     * @param $hash
     * @param $lang
     * @param $realFileName
     *
     * @return int
     */
    protected function _linkToCache( $dir, $hash, $lang, $realFileName ) {
        return file_put_contents( $dir . DIRECTORY_SEPARATOR . $hash . "|" . $lang, $realFileName . "\n", FILE_APPEND | LOCK_EX );
    }

    /**
     **********************************************************************************************
     * 3. PROJECT
     **********************************************************************************************
     */

    /**
     *
     * Used when we get info to download the original file
     *
     * @param $id_job
     * @param $id_file
     * @param $password
     *
     * @return array
     */
    public function getOriginalFilesForJob( $id_job, $id_file, $password ) {

        $where_id_file = "";
        if ( !empty( $id_file ) ) {
            $where_id_file = " and fj.id_file=$id_file";
        }
        $query = "select fj.id_file, f.filename, f.id_project, j.source, mime_type, sha1_original_file, create_date from files_job fj
			inner join files f on f.id=fj.id_file
			inner join jobs j on j.id=fj.id_job
			where fj.id_job=$id_job $where_id_file and j.password='$password'";

        $db      = \Database::obtain();
        $results = $db->fetch_array( $query );

        foreach ( $results as $k => $result ) {
            //try fetching from files dir
            $filePath                            = $this->getOriginalFromFileDir( $result[ 'id_file' ], $result[ 'sha1_original_file' ] );
            $results[ $k ][ 'originalFilePath' ] = $filePath;
        }

        return $results;
    }

    /**
     * Used when we take the files after the translation ( Download )
     *
     * @param $id_job
     * @param $id_file
     *
     * @return array
     */
    public function getFilesForJob( $id_job, $id_file ) {

        $where_id_file = "";
        if ( !empty( $id_file ) ) {
            $where_id_file = " and `id_file`=$id_file";
        }
        $query = "SELECT `fj`.`id_file`, `f`.`filename`, `f`.`id_project`, `j`.`source`, `mime_type`, `sha1_original_file`
            FROM `files_job` `fj`
            INNER JOIN `files` `f` ON `f`.`id`=`fj`.`id_file`
            JOIN `jobs` AS `j` ON `j`.`id`=`fj`.`id_job`
            WHERE `fj`.`id_job` = $id_job $where_id_file
            GROUP BY `id_file`";
        $db      = \Database::obtain();
        $results = $db->fetch_array( $query );

//        $query       = 'SELECT `fj`.`id_file`, `f`.`filename`, `f`.`id_project`, `j`.`source`, `mime_type`, `sha1_original_file`
//            FROM `files_job` `fj`
//            INNER JOIN `files` `f` ON `f`.`id`=`fj`.`id_file`
//            JOIN `jobs` AS `j` ON `j`.`id`=`fj`.`id_job`
//            WHERE `fj`.`id_job` = :id_job';
//
//
//        if ( !empty( $id_file ) ) {
//            $query .= " AND `id_file` = :id_file ";
//        }
//
//        $query       .= " GROUP BY `id_file`";
//
//        $bindParams = [
//                ':id_job' => $id_job
//        ];
//
//        if ( !empty( $id_file ) ) {
//            $bindParams[ ':id_file' ] = $id_file;
//        }
//
//        $db      = \Database::obtain();
//
//        try {
//            $results = $db->fetch_array( $query );
//        } catch (\Exception $e){
//            var_dump($query);
//            var_dump($bindParams);
//            var_dump($e->getMessage());
//            die();
//        }

        foreach ( $results as $k => $result ) {
            //try fetching from files dir
            $originalPath = $this->getOriginalFromFileDir( $result[ 'id_file' ], $result[ 'sha1_original_file' ] );

            $results[ $k ][ 'originalFilePath' ] = $originalPath;

            //note that we trust this to succeed on first try since, at this stage, we already built the file package
            $results[ $k ][ 'xliffFilePath' ] = $this->getXliffFromFileDir( $result[ 'id_file' ], $result[ 'sha1_original_file' ] );
        }

        return $results;
    }

    /**
     **********************************************************************************************
     * 4. ZIP ARCHIVES HANDLING
     **********************************************************************************************
     */

    /**
     * Gets the file path of the temporary uploaded zip, when the project is not
     * yet created. Useful to perform preliminary validation on the project.
     * This function was created to perform validations on the TKIT zip file
     * format loaded via API.
     *
     * XXX: This function only handles the case in which the zip file is *one* for the
     * project.
     *
     * @param $uploadToken
     *
     * @return bool|string
     */
    public function getTemporaryUploadedZipFile( $uploadToken ) {
        $files    = scandir( \INIT::$QUEUE_PROJECT_REPOSITORY . '/' . $uploadToken );
        $zip_name = null;
        $zip_file = null;

        foreach ( $files as $file ) {
            \Log::doJsonLog( $file );
            if ( strpos( $file, static::ORIGINAL_ZIP_PLACEHOLDER ) !== false ) {
                $zip_name = $file;
            }
        }

        $files = scandir( \INIT::$ZIP_REPOSITORY . '/' . $zip_name );
        foreach ( $files as $file ) {
            if ( strpos( $file, '.zip' ) !== false ) {
                $zip_file = $file;
                break;
            }
        }

        if ( $zip_name == null && $zip_file == null ) {
            return false;
        } else {
            return \INIT::$ZIP_REPOSITORY . '/' . $zip_name . '/' . $zip_file;
        }
    }
}
