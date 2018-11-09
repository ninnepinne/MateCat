<?php

/**
 * Created by PhpStorm.
 * User: Hashashiyyin
 * Date: 17/05/16
 * Time: 11:49
 */
class Engines_Results_MyMemory_TagProjectionResponse extends Engines_Results_AbstractResponse {

    public function __construct( $response ){

        $this->responseData    = isset( $response[ 'data' ][ 'translation' ] ) ? CatUtils::subFilterRawDatabaseXliffForView( $response[ 'data' ][ 'translation' ] ) : '';

    }

}