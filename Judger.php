<?php
namespace App\Babel\Extension\noiopen;

use App\Babel\Submit\Curl;
use App\Models\Submission\SubmissionModel;
use App\Models\Eloquent\Problem;
use KubAT\PhpSimple\HtmlDomParser;
use App\Models\JudgerModel;
use Requests;
use Exception;
use Log;

class Judger extends Curl
{

    public $verdict=[
        'Accepted'=>"Accepted",
        'Wrong Answer'=>"Wrong Answer",
        "Presentation Error"=>"Presentation Error",
        'Time Limit Exceeded'=>"Time Limit Exceed",
        "Memory Limit Exceeded"=>"Memory Limit Exceed",
        'Runtime Error'=>"Runtime Error",
        'Output Limit Exceeded'=>"Output Limit Exceeded",
        'Compile Error'=>"Compile Error",
        'System Error'=>"System Error",
    ];
    private $model=[];
    private $noiopen=[];


    public function __construct()
    {
        $this->model["submissionModel"]=new SubmissionModel();
        $this->model["judgerModel"]=new JudgerModel();
    }

    public function judge($row)
    {
        $sub=[];

        if (!isset($this->noiopen[$row['remote_id']])) {
            $judgerDetail=$this->model["judgerModel"]->detail($row['jid']);
            $this->appendNOIOpenStatus($judgerDetail['handle'], $row['pid'], $row['remote_id']);
            if (!isset($this->noiopen[$row['remote_id']])) {
                return;
            }
        }

        $status=$this->noiopen[$row['remote_id']];

        if(!isset($this->verdict[$status['verdict']])) {
            return ;
        }

        if($status['verdict']=='Waiting'){
            return ;
        }

        $sub['verdict']=$this->verdict[$status['verdict']];
        $sub['compile_info']=$status['compile_info'];
        $sub["score"]=$sub['verdict']=="Accepted" ? 1 : 0;
        $sub['time']=$status['time'];
        $sub['memory']=$status['memory'];
        $sub['remote_id']=$row['remote_id'];

        $this->model["submissionModel"]->updateSubmission($row['sid'], $sub);
    }

    private function appendNOIOpenStatus($judger, $pid, $remoteID)
    {
        $origin = Problem::findOrFail($pid)->origin;
        $contestChar = explode('/', explode('http://noi.openjudge.cn/', $origin, 2)[1], 2)[0];
        $submissionDetailHTML=$this->grab_page([
            'site' => "http://noi.openjudge.cn/$contestChar/solution/$remoteID/",
            'oj' => 'noiopen',
            'handle' => $judger,
        ]);
        $submissionDetail=HtmlDomParser::str_get_html($submissionDetailHTML, true, true, DEFAULT_TARGET_CHARSET, false);
        $compileDetailHTML=$submissionDetail->find('div.compile-info dl', 0)->innertext;
        $memory=0;
        $time=0;
        if(mb_strpos($compileDetailHTML,'<dt>内存:</dt>')!==false) {
            $memory=(explode('<dd>', explode('kB</dd>', explode('<dt>内存:</dt>', $compileDetailHTML, 2)[1], 2)[0], 2)[1]);
        }
        if(mb_strpos($compileDetailHTML,'<dt>时间:</dt>')!==false) {
            $time=(explode('<dd>', explode('ms</dd>', explode('<dt>时间:</dt>', $compileDetailHTML, 2)[1], 2)[0], 2)[1]);
        }
        $this->noiopen[$remoteID]=[
            'verdict'=>trim($submissionDetail->find('p.compile-status a', 0)->plaintext),
            'memory'=>$memory,
            'time'=>$time,
            'compile_info'=>null,
        ];
        if ($this->noiopen[$remoteID]['verdict']=='Compile Error') {
            $this->noiopen[$remoteID]['compile_info']=$submissionDetail->find('pre', 0)->innertext;
        }
    }
}
