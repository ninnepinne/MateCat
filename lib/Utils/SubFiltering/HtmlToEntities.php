<?php
/**
 * Created by PhpStorm.
 * @author domenico domenico@translated.net / ostico@gmail.com
 * Date: 05/11/18
 * Time: 17.34
 *
 */

namespace SubFiltering;


class HtmlToEntities extends AbstractChannelHandler {

    /**
     * @param $segment
     *
     * @return string
     */
    public function transform( $segment ){
        return htmlspecialchars( $segment, ENT_XML1 | ENT_QUOTES, 'UTF-8', true );
    }

}