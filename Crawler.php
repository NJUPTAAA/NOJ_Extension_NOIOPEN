<?php
namespace App\Babel\Extension\noiopen;

use App\Babel\Crawl\CrawlerBase;
use App\Models\ProblemModel;
use App\Models\OJModel;
use KubAT\PhpSimple\HtmlDomParser;
use Requests;
use Exception;

class Crawler extends CrawlerBase
{
    public $oid=null;
    public $prefix="NOIOPJ";
    private $ignoreCon=['math'];
    private $con;
    private $action;
    private $cached;
    private $imgi;
    /**
     * Initial
     *
     * @return Response
     */
    public function start($conf)
    {
        $this->action=isset($conf["action"])?$conf["action"]:'crawl_problem';
        $this->cached=isset($conf["cached"])?$conf["cached"]:false;
        $con=isset($conf["con"])?$conf["con"]:'all';
        $this->oid=OJModel::oid('noiopen');

        if(is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }

        if ($this->action=='judge_level') {
            $this->judge_level();
        } else {
            $this->crawl($con);
        }
    }

    public function judge_level()
    {
        // TODO
    }

    private function cachedInnerText($html, $tag='dd')
    {
        return $this->cacheImage(HtmlDomParser::str_get_html($html, true, true, DEFAULT_TARGET_CHARSET, false))->find($tag, 0)->innertext;
    }

    private function cacheImage($dom)
    {
        foreach ($dom->find('img') as $ele) {
            $src=str_replace('\\', '/', $ele->src);
            if (strpos($src, '://')!==false) {
                $url=$src;
            } elseif ($src[0]=='/') {
                $url='http://noi.openjudge.cn'.$src;
            } else {
                $url='http://noi.openjudge.cn/'.$src;
            }
            $res=Requests::get($url, ['Referer' => 'http://noi.openjudge.cn']);
            $ext=['image/jpeg'=>'.jpg', 'image/png'=>'.png', 'image/gif'=>'.gif', 'image/bmp'=>'.bmp'];
            if (isset($res->headers['content-type'])) {
                $cext=$ext[$res->headers['content-type']];
            } else {
                $pos=strpos($ele->src, '.');
                if ($pos===false) {
                    $cext='';
                } else {
                    $cext=substr($ele->src, $pos);
                }
            }
            $fn=$this->con.'_'.($this->imgi++).$cext;
            $dir=base_path("public/external/noiopen/img");
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(base_path("public/external/noiopen/img/$fn"), $res->body);
            $ele->src='/external/noiopen/img/'.$fn;
        }
        return $dom;
    }

    public function crawl($con)
    {
        $this->line("<fg=yellow>Fetching:   </>General List");
        $ignoreCon = $this->ignoreCon;
        $NOIHomePage=HtmlDomParser::str_get_html(Requests::get("http://noi.openjudge.cn/", ['Referer' => 'http://noi.openjudge.cn'])->body, true, true, DEFAULT_TARGET_CHARSET, false);
        $conElement=$NOIHomePage->find(".practice-info h3 a");
        $allCon=[];
        foreach($conElement as $element){
            $tempCon=$element->href;
            if(!in_array($tempCon, $ignoreCon)){
                $allCon[]=trim($tempCon, " \t\n\r\0\x0B/");
            }
        }
        $this->line("<fg=green>Fetched:    </>General List");
        if($con=='all'){
            foreach($allCon as $contestID){
                $this->crawlContest($contestID);
            }
        }elseif(in_array($con, $ignoreCon)){
            $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>The specified con is configured to be ignored.</>\n");
        }else{
            foreach($allCon as $contestID){
                if ($con==$contestID) {
                    $this->crawlContest($contestID);
                }
            }
        }
    }

    protected function crawlContest($contestID)
    {
        $NOIContestPage=HtmlDomParser::str_get_html(Requests::get("http://noi.openjudge.cn/$contestID/", ['Referer' => 'http://noi.openjudge.cn'])->body, true, true, DEFAULT_TARGET_CHARSET, false);
        $probElement=$NOIContestPage->find(".problem-id a");
        $NOIContestRankingPage=HtmlDomParser::str_get_html(Requests::get("http://noi.openjudge.cn/$contestID/ranking/", ['Referer' => 'http://noi.openjudge.cn'])->body, true, true, DEFAULT_TARGET_CHARSET, false);
        $contestRealID=$NOIContestRankingPage->find("input[name='contestId']", 0)->value;
        foreach($probElement as $probID){
            $this->_crawl(trim($probID->href), trim($probID->plaintext), trim($contestRealID), 5);
        }
    }

    protected function _crawl($contestProblemUrl, $problemInternalID, $contestRealID, $retry=1)
    {
        $attempts=1;
        while($attempts <= $retry){
            try{
                $this->__crawl($contestProblemUrl, $problemInternalID, $contestRealID);
            }catch(Exception $e){
                $attempts++;
                $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>{$e->getMessage()} at {$e->getFile()}:{$e->getLine()}</>\n");
                continue;
            }
            break;
        }
    }

    protected function __crawl($contestProblemUrl, $problemInternalID, $contestRealID)
    {
        $this->_resetPro();
        $this->imgi=1;
        $probUrl="http://noi.openjudge.cn$contestProblemUrl"; // http://noi.openjudge.cn/ch0113/01/
        $con=strtoupper(str_replace('/','',$contestProblemUrl));
        $this->con=$con;
        $problemModel=new ProblemModel();
        if(!empty($problemModel->basic($problemModel->pid($this->prefix.$con))) && $this->action=="update_problem"){
            return;
        }
        if($this->action=="crawl_problem") $this->line("<fg=yellow>Crawling:   </>{$this->prefix}{$con}");
        elseif($this->action=="update_problem") $this->line("<fg=yellow>Updating:   </>{$this->prefix}{$con}");
        else return;
        $res=Requests::get($probUrl, ['Referer' => 'http://noi.openjudge.cn']);
        $NOIProblemPage=HtmlDomParser::str_get_html($res->body, true, true, DEFAULT_TARGET_CHARSET, false);
        $this->pro['pcode']=$this->prefix.$con;
        $this->pro['OJ']=$this->oid;
        $this->pro['contest_id']=$contestRealID;
        $this->pro['index_id']=$problemInternalID;
        $this->pro['origin']=$probUrl;
        $this->pro['title']=explode(":",$NOIProblemPage->find("div#pageTitle", 0)->plaintext,2)[1];
        $problemParams=$NOIProblemPage->find(".problem-params dd");
        $this->pro['time_limit']=explode('ms',$problemParams[0]->plaintext)[0];
        $this->pro['memory_limit']=explode('kB',$problemParams[1]->plaintext)[0];
        $this->pro['solved_count']=explode('<dd>',explode('</dd>',explode('通过人数</dt>', $res->body)[1],2)[0])[1];
        $this->pro['input_type']='standard input';
        $this->pro['output_type']='standard output';

        $mainProblemHTML=$NOIProblemPage->find("dl.problem-content", 0)->innertext;

        if(mb_strpos($mainProblemHTML,'<dt>描述</dt>')!==false) {
            $this->pro['description']=$this->cachedInnerText(explode('</dd>', explode('<dt>描述</dt>', $mainProblemHTML, 2)[1], 2)[0]);
        }
        if(mb_strpos($mainProblemHTML,'<dt>输入</dt>')!==false) {
            $this->pro['input']=$this->cachedInnerText(explode('</dd>', explode('<dt>输入</dt>', $mainProblemHTML, 2)[1], 2)[0]);
        }
        if(mb_strpos($mainProblemHTML,'<dt>输出</dt>')!==false) {
            $this->pro['output']=$this->cachedInnerText(explode('</dd>', explode('<dt>输出</dt>', $mainProblemHTML, 2)[1], 2)[0]);
        }

        $sampleInput=null;
        $sampleOutput=null;
        if(mb_strpos($mainProblemHTML,'<dt>样例输入</dt>')!==false) {
            $sampleInput=$this->cachedInnerText(explode('</dd>', explode('<dt>样例输入</dt>', $mainProblemHTML, 2)[1], 2)[0], 'pre');
        }
        if(mb_strpos($mainProblemHTML,'<dt>样例输出</dt>')!==false) {
            $sampleOutput=$this->cachedInnerText(explode('</dd>', explode('<dt>样例输出</dt>', $mainProblemHTML, 2)[1], 2)[0], 'pre');
        }
        $this->pro['sample']=[['sample_input'=>$sampleInput, 'sample_output'=>$sampleOutput]];


        $note=null;
        if(mb_strpos($mainProblemHTML,'<dt>提示</dt>')!==false) {
            $note.=$this->cachedInnerText(explode('</dd>', explode('<dt>提示</dt>', $mainProblemHTML, 2)[1], 2)[0]);
        }
        if(mb_strpos($mainProblemHTML,'<dt>来源</dt>')!==false) {
            if(!is_null($note)){
                $note.="<br><br>";
            }
            $note.='Source: '.$this->cachedInnerText(explode('</dd>', explode('<dt>来源</dt>', $mainProblemHTML, 2)[1], 2)[0]);
        }
        $this->pro['note']=$note;
        $this->pro['source']=trim($NOIProblemPage->find("div.contest-title-tab h2", -1)->plaintext);

        $problem=$problemModel->pid($this->pro['pcode']);

        if ($problem) {
            $problemModel->clearTags($problem);
            $new_pid=$this->updateProblem($this->oid);
        } else {
            $new_pid=$this->insertProblem($this->oid);
        }

        if($this->action=="crawl_problem") $this->line("<fg=green>Crawled:    </>{$this->prefix}{$con}");
        elseif($this->action=="update_problem") $this->line("<fg=green>Updated:    </>{$this->prefix}{$con}");
    }
}
