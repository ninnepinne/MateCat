<?php

class Segments_SegmentMetadataDao extends DataAccess_AbstractDao {

    /**
     * get all meta
     *
     * @param int $id_segment
     * @param int $ttl
     *
     * @return DataAccess_IDaoStruct[]
     */
    public static function getAll($id_segment, $ttl = 86400){

        $thisDao = new self();
        $conn = $thisDao->getDatabaseHandler();
        $stmt = $conn->getConnection()->prepare( "SELECT * FROM segment_metadata WHERE id_segment = ? " );

        return $thisDao->setCacheTTL( $ttl )->_fetchObject( $stmt,
                new Segments_SegmentMetadataStruct(),
                [ $id_segment ]
        );
    }

    /**
     * get key
     *
     * @param int $id_segment
     * @param string $key
     * @param int $ttl
     *
     * @return DataAccess_IDaoStruct|null
     */
    public static function get($id_segment, $key, $ttl = 86400){

        $thisDao = new self();
        $conn = $thisDao->getDatabaseHandler();
        $stmt = $conn->getConnection()->prepare( "SELECT * FROM segment_metadata WHERE id_segment = ? and meta_key = ? " );

        $data = $thisDao->setCacheTTL( $ttl )->_fetchObject( $stmt,
                new Segments_SegmentMetadataStruct(),
                [ $id_segment, $key ]
        );

        if(isset($data[0])){
            return $data[0];
        }

        return null;
    }

    /**
     * @param Segments_SegmentMetadataStruct $metadataStruct
     */
    public static function save(Segments_SegmentMetadataStruct $metadataStruct) {
        $conn = Database::obtain()->getConnection();
        $stmt = $conn->prepare( "INSERT INTO segment_metadata " .
                " ( id_segment, meta_key, meta_value  ) VALUES " .
                " ( :id_segment, :key, :value ) "
        );

        $stmt->execute( [
                'id_segment' => $metadataStruct->id_segment,
                'key' => $metadataStruct->meta_key,
                'value' => $metadataStruct->meta_value,
        ] );
    }
}