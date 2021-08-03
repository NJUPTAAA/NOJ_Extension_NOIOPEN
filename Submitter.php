<?php
namespace App\Babel\Extension\noiopen;

use App\Babel\Submit\Curl;
use App\Models\OJModel;
use App\Models\JudgerModel;
use Illuminate\Support\Facades\Validator;
use Requests;

class Submitter extends Curl
{
    public $oid=null;
    protected $sub;
    public $post_data=[];
    protected $selectedJudger;

    public function __construct(& $sub, $all_data)
    {
        $this->sub=& $sub;
        $this->post_data=$all_data;
        $judger=new JudgerModel();
        $this->oid=OJModel::oid('noiopen');
        $judger_list=$judger->list($this->oid);
        $this->selectedJudger=$judger_list[array_rand($judger_list)];
    }

    private function _login()
    {
        $response=$this->grab_page([
            "site"=>'http://noi.openjudge.cn',
            "oj"=>'noiopen',
            "handle"=>$this->selectedJudger["handle"]
        ]);
        if (mb_strpos($response, '登出')===false) {
            $params=[
                'email' => $this->selectedJudger["handle"],
                'password' => $this->selectedJudger["password"],
                'redirectUrl' => '',
            ];
            $this->login([
                "url"=>'http://noi.openjudge.cn/api/auth/login',
                "data"=>http_build_query($params),
                "oj"=>'noiopen',
                "ret"=>true,
                "handle"=>$this->selectedJudger["handle"]
            ]);
        }
    }

    private function _submit()
    {
        $this->sub["jid"]=$this->selectedJudger["jid"];

        $params=[
            'contestId' => $this->post_data['cid'],
            'problemNumber' => $this->post_data['iid'],
            'language' => $this->post_data['lang'],
            'source' => base64_encode($this->post_data["solution"]),
            'sourceEncode' => 'base64',
        ];

        $response=$this->post_data([
            "site"=>"http://noi.openjudge.cn/api/solution/submit",
            "data"=>http_build_query($params),
            "oj"=>"noiopen",
            "ret"=>true,
            "follow"=>false,
            "returnHeader"=>false,
            "postJson"=>false,
            "extraHeaders"=>[],
            "handle"=>$this->selectedJudger["handle"]
        ]);

        $response=json_decode($response, true);
        if ($response["result"]=="ERROR") {
            $this->sub['verdict']='Submission Error';
        } else {
            $submissionURL=$response["redirect"];
            $this->sub['remote_id']=explode('/',explode("solution/",$submissionURL)[1])[0];
        }
    }

    public function submit()
    {
        $validator=Validator::make($this->post_data, [
            'pid' => 'required|integer',
            'coid' => 'required|integer',
            'iid' => 'required',
            'cid' => 'required',
            'solution' => 'required',
        ]);

        if ($validator->fails()) {
            $this->sub['verdict']="System Error";
            return;
        }

        $this->_login();
        $this->_submit();
    }
}
