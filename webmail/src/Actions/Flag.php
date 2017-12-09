<?php

namespace App\Actions;

use App\Model\Task as TaskModel;
use App\Actions\Base as BaseAction;
use App\Model\Message as MessageModel;

class Flag extends Base
{
    public function update( MessageModel $message )
    {
        $this->setFlag( $message, MessageModel::FLAG_FLAGGED, TRUE );
    }

    public function getType()
    {
        return TaskModel::TYPE_FLAG;
    }
}