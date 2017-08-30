<?php



namespace Features\Dqf\Model ;

use Exception;
use Features\Dqf\Service\MasterProject;
use Features\Dqf\Service\AbstractProjectFiles;
use Features\Dqf\Service\MasterProjectFiles;
use Features\Dqf\Service\MasterProjectReviewSettings;
use Features\Dqf\Service\MasterProjectSegmentsBatch;
use Features\Dqf\Service\Session;
use Features\Dqf\Service\Struct\CreateProjectResponseStruct;
use Features\Dqf\Service\Struct\ProjectCreationStruct;
use Features\Dqf\Service\Struct\ProjectRequestStruct;
use Features\Dqf\Service\Struct\Response\MaserFileCreationResponseStruct;
use Features\Dqf\Service\Struct\Response\ReviewSettingsResponseStruct;
use Features\Dqf\Utils\Functions;
use Features\Dqf\Utils\ProjectMetadata;
use Files_FileDao;
use Projects_ProjectDao;
use Projects_ProjectStruct;
use Utils;


class ProjectCreation {

    protected $intermediateRootProjectRequired = false;

    /**
     * @var Projects_ProjectStruct
     */
    protected $project ;

    protected $current_state ;

    protected $logger ;

    /**
     * @var Session
     */
    protected $ownerSession ;

    /**
     * @var CreateProjectResponseStruct
     */
    protected $remoteMasterProject ;

    /**
     * @var ProjectCreationStruct
     */
    protected $inputStruct ;

    /**
     * @var MaserFileCreationResponseStruct[]
     */
    protected $remoteFiles ;

    /**
     * @var array
     */
    protected $segmentsBatchResult ;

    /**
     * @var ReviewSettingsResponseStruct ;
     */
    protected $reviewSettings ;

    public function __construct( ProjectCreationStruct $struct ) {
        $this->inputStruct = $struct  ;
        $this->project = Projects_ProjectDao::findById( $struct->id_project );

        // find  back the qa_model file from json?
        // no the quality model must be found trom
    }

    public function setLogger($logger) {
        $this->logger = $logger ;
    }

    public function process() {
        $this->_initSession();
        $this->_createProject();
        $this->_submitProjectFiles();
        $this->_submitSourceSegments();
        $this->_submitReviewSettings();

        $intermediateRootProjectRequired = $this->project->getFeatures()->filter(
                'filterDqfIntermediateProjectRequired', false
        );

        if ( !$intermediateRootProjectRequired ) {
            $this->_submitChildProjects();
        }
    }

    protected function _createProject() {
        $projectInputParams = ProjectMetadata::extractProjectParameters( $this->project->getMetadataAsKeyValue() );

        $params = new ProjectRequestStruct(array_merge( array(
                'name'               => $this->project->name,
                'sourceLanguageCode' => $this->inputStruct->source_language,
                'clientId'           => Functions::scopeId( $this->project->id ),
                'templateName'       => '',
                'tmsProjectKey'      => ''
        ), $projectInputParams ) );

        $project = new MasterProject($this->ownerSession);
        $this->remoteMasterProject = $project->create( $params ) ;

        foreach( $this->project->getChunks() as $chunk ) {
            $struct = new DqfProjectMapStruct([
                    'id_job'           => $chunk->id,
                    'password'         => $chunk->password,
                    'first_segment'    => $chunk->job_first_segment,
                    'last_segment'     => $chunk->job_last_segment,
                    'dqf_project_id'   => $this->remoteMasterProject->dqfId,
                    'dqf_project_uuid' => $this->remoteMasterProject->dqfUUID,
                    'create_date'      => Utils::mysqlTimestamp( time() )
            ]);

            DqfProjectMapDao::insertStruct( $struct ) ;
        }
    }

    protected function _submitProjectFiles() {
        $files = Files_FileDao::getByProjectId($this->project->id) ;
        $remoteFiles = new MasterProjectFiles(
                $this->ownerSession,
                $this->remoteMasterProject
        );

        foreach( $files as $file ) {
            $segmentsCount = $this->inputStruct->file_segments_count[ $file->id ];
            $remoteFiles->setFile( $file, $segmentsCount );
        }

        $remoteFiles->setTargetLanguages( $this->project->getTargetLanguages() );

        $this->remoteFiles = $remoteFiles->submitFiles();
    }

    protected function _submitReviewSettings() {
        $dqfQaModel = new DqfQualityModel( $this->project ) ;
        $request = new MasterProjectReviewSettings( $this->ownerSession, $this->remoteMasterProject );

        $struct = $dqfQaModel->getReviewSettings() ;

        $this->reviewSettings = $request->create( $struct );
    }

    protected function _initSession() {
        $user               = ( new UserModel( $this->project->getOwner() ) );
        $this->ownerSession = $user->getSession()->login() ;
    }

    protected function _submitSourceSegments() {
        $batchSegments = new MasterProjectSegmentsBatch(
                $this->ownerSession,
                $this->remoteMasterProject,
                $this->remoteFiles
        );

        $results = $batchSegments->getResult() ;

        foreach( $results as $result ) {
            if ( empty( $result->segmentList ) ) {
                throw new Exception('segmentList is empty');
            }
            $this->_saveSegmentsList( $result->segmentList ) ;
        }
    }

    protected function _saveSegmentsList( $segmentList ) {
        $dao = new DqfSegmentsDao() ;
        $dao->insertBulkMap( array_map(function( $item ) {
            return [
                    Functions::descope($item['clientId']),
                    $item['dqfId'],
                    null
            ];
        }, $segmentList ) ) ;
    }

    protected function _submitChildProjects() {
        foreach( $this->project->getChunks() as $chunk ) {
            $project = new ChildProjectCreationModel( $this->remoteMasterProject, $chunk ) ;
            $project->setOwnerSession( $this->ownerSession );
            $project->setFiles( $this->remoteFiles ) ;
            $project->createForTranslation() ;
        }
    }
}