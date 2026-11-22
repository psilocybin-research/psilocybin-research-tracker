<?php
declare(strict_types=1);

/** Generates trusted LaTeX; no user-supplied TeX commands are ever compiled. */
final class AccountReport
{
    public const VERSION='2026-11-08-r1';
    public static function escape(string $value): string
    {
        $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value)??'';
        return strtr($value,['\\'=>'\textbackslash{}','{'=>'\{','}'=>'\}','%'=>'\%','$'=>'\$','&'=>'\&','#'=>'\#','_'=>'\_','~'=>'\textasciitilde{}','^'=>'\textasciicircum{}']);
    }
    private static function wrap(string $value): string
    {
        $characters=preg_split('//u',$value,-1,PREG_SPLIT_NO_EMPTY)?:[];$out='';
        foreach($characters as $char)$out.=($char===' '?'\ ':self::escape($char)).'\allowbreak{}';
        return $out;
    }
    private static function literal(string $text,int $limit): string
    {
        $lines=preg_split('/\R/u',$text)?:[];$total=count($lines);$out="\\begingroup\\ttfamily\\footnotesize\\raggedright\\setlength{\\parskip}{2pt}\n";
        foreach(array_slice($lines,0,$limit) as $line){$line=str_replace("\t",'    ',$line);$out.='\noindent\strut '.self::wrap(mb_substr($line,0,350)).(mb_strlen($line)>350?' [line excerpt]':'')."\\par\n";}
        $out.="\\endgroup\n";if($total>$limit)$out.='\smallskip\noindent\textit{Excerpt: first '.$limit.' of '.$total.' lines. The source archive contains the complete file.}' ."\n";
        return $out;
    }
    public static function files(array $a,string $csv,string $author=''): array
    {
        $e=self::escape(...);$title=$e($a['title']);$snapshot=$a['snapshot'];$run=$e($a['run_at_utc']?:'Not recorded');$date=$e($snapshot['generated_at_utc']);$count=number_format((int)$snapshot['record_count']);$hash=self::wrap($snapshot['sha256']);
        $tex=<<<'TEX'
\documentclass[11pt,a4paper,parskip=half,headings=normal]{scrartcl}
\usepackage[margin=24mm,headheight=18pt,footskip=12mm]{geometry}
\usepackage{fontspec}
\setmainfont{DejaVuSerif.ttf}[Path=fonts/,BoldFont=DejaVuSerif-Bold.ttf,ItalicFont=DejaVuSerif-Italic.ttf,BoldItalicFont=DejaVuSerif-BoldItalic.ttf]
\setsansfont{DejaVuSans.ttf}[Path=fonts/,BoldFont=DejaVuSans-Bold.ttf,ItalicFont=DejaVuSans-Oblique.ttf,BoldItalicFont=DejaVuSans-BoldOblique.ttf]
\setmonofont{DejaVuSansMono.ttf}[Path=fonts/,BoldFont=DejaVuSansMono-Bold.ttf,Scale=MatchLowercase]
\usepackage{xcolor,graphicx,booktabs,longtable,array,ragged2e}
\usepackage{scrlayer-scrpage}
\usepackage[unicode,hidelinks]{hyperref}
\usepackage{bookmark}
\definecolor{ResearchGreen}{HTML}{123C31}
\definecolor{ResearchMuted}{HTML}{626C64}
\setkomafont{section}{\sffamily\large\color{ResearchGreen}}
\setkomafont{subsection}{\sffamily\normalsize\color{ResearchGreen}}
\setkomafont{caption}{\small}
\setkomafont{captionlabel}{\sffamily}
\clearpairofpagestyles
\ihead{\sffamily\footnotesize\color{ResearchGreen}Psilocybin-Research.com}
\ohead{\sffamily\footnotesize WebR analysis}
\ifoot{\sffamily\scriptsize\color{ResearchMuted}Exploratory user-authored analysis}
\ofoot{\sffamily\footnotesize\pagemark}
\pagestyle{scrheadings}
\setlength{\emergencystretch}{3em}
\setcounter{secnumdepth}{0}
\hypersetup{pdfcreator={Psilocybin Research report exporter / Tectonic},pdfauthor={Research workspace user},pdftitle={Research analysis}}
\begin{document}
TEX;
        $tex.="\n{\\sffamily\\small\\color{ResearchGreen}RESEARCH WORKSPACE REPORT}\\par\n\\vspace{4mm}\n{\\sffamily\\LARGE\\raggedright ".$title."\\par}\n\\vspace{2mm}\n{\\color{ResearchMuted}Exploratory analysis of public bibliographic metadata}\\par\n";
        if($author!=='')$tex.='\smallskip\noindent Prepared by '.$e(mb_substr($author,0,120)).'\par'."\n";
        $tex.="\\section{Dataset and execution}\n\\begin{tabular}{@{}>{\\RaggedRight\\arraybackslash}p{36mm}>{\\RaggedRight\\arraybackslash}p{119mm}@{}}\nDataset & Public psilocybin and psilocin research index \\\\\nRecords & ".$count." \\\\\nSnapshot (UTC) & ".$date." \\\\\nRun (UTC) & ".$run." \\\\\nRuntime & webR ".$e((string)($a['runtime']['webR']??'0.6.0'))."; R session recorded in Appendix C \\\\\nInitial seed & ".$e((string)($a['seed']??20261108))." (user code can override it) \\\\\n\\end{tabular}\n\\smallskip\n{\\footnotesize Snapshot SHA-256: \\texttt{".$hash."}}\\par\n";
        if($a['results_csv']!=='') {
            $stream=fopen('php://temp','w+');fwrite($stream,$a['results_csv']);rewind($stream);$header=fgetcsv($stream,0,',','"','');$rows=[];while(($row=fgetcsv($stream,0,',','"',''))!==false){$rows[]=$row;if(count($rows)>60)break;}fclose($stream);
            if(is_array($header)&&count($header)) {
                $cols=min(count($header),6);$width=round(156/$cols-4,1);$format=str_repeat('>{\RaggedRight\arraybackslash}p{'.$width.'mm}',$cols);$tex.="\\section{Results table}\n{\\small\n\\setlength{\\tabcolsep}{2mm}\n\\begin{longtable}{@{}".$format."@{}}\n\\toprule\n";
                $heads=implode(' & ',array_map(static fn($v)=>'\textbf{'.self::wrap(mb_substr((string)$v,0,100)).'}',array_slice($header,0,$cols)));$tex.=$heads." \\\\\n\\midrule\n\\endfirsthead\n\\toprule\n".$heads." \\\\\n\\midrule\n\\endhead\n\\bottomrule\n\\endfoot\n";
                foreach(array_slice($rows,0,60) as $row){$cells=[];for($i=0;$i<$cols;$i++){$v=(string)($row[$i]??'');$cells[]=self::wrap(mb_substr($v,0,160)).(mb_strlen($v)>160?' \\textit{[excerpt]}':'');}$tex.=implode(' & ',$cells)." \\\\\n";}
                $tex.="\\end{longtable}}\n\\noindent\\footnotesize Table preview: up to 60 rows and 6 columns; long cells are abbreviated. The complete table is in \\texttt{results.csv}.\\normalsize\n";
            }
        } else $tex.="\\section{Results}\nNo data frame named \\texttt{results} was exported by this run. See the figures and console output for the recorded results.\n";
        $files=[];
        if ($a['plots']) $tex.="\\clearpage\n\\section{Figures}\n";
        foreach($a['plots'] as $i=>$png){$name='figures/plot-'.($i+1).'.png';$files[$name]=base64_decode(substr($png,22));$tex.="\\begin{figure}[!htbp]\n\\centering\n\\includegraphics[width=\\linewidth,height=.72\\textheight,keepaspectratio]{".$name."}\n\\caption{R-generated plot ".($i+1)." from this analysis.}\\end{figure}\n";}
        $tex.="\\clearpage\n\\section{Interpretation and limitations}\nThese outputs were generated by user-authored R code and have not been independently verified. Indexed records are not independent studies or participants. Registry entries, preprints and journal articles may describe the same study. Topics and study types are derived labels and should be checked against original sources. Recent years may be incomplete. This report does not establish clinical effects.\n\\section{Source and reproducibility}\nPsilocybin Research Database. Public metadata snapshot generated ".$date."; ".$count." records. This live-index snapshot is separate from a deposited Zenodo release. Dataset citation and reuse information: \\url{https://psilocybin-research.com/research-dataset.php}.\n\\par The source archive includes the exact \\texttt{records.csv}, user R code, output, table, plots, R session information and SHA-256 checksums. Re-run the code with compatible R and package versions to assess reproducibility. This PDF records saved outputs; creating it does not re-execute R.\n";
        $tex.="\\appendix\n\\section{Appendix A: R code}\n".self::literal($a['code'],180)."\\section{Appendix B: Console output}\n".self::literal($a['output']?:'No console output was recorded.',120)."\\section{Appendix C: R session}\n".self::literal($a['session_info']?:'No R session information was recorded.',80)."\n\\end{document}\n";
        $files['report.tex']=$tex;$files['user-code.R']=$a['code'];$files['analysis.R']="records <- read.csv('records.csv', check.names=FALSE, stringsAsFactors=FALSE, na.strings=c('', 'NA'))\nset.seed(20261108)\n".$a['code'];$files['records.csv']=$csv;$files['results.csv']=$a['results_csv'];$files['output.txt']=$a['output'];$files['sessionInfo.txt']=$a['session_info'];
        $files['provenance.json']=json_encode(['report_template'=>self::VERSION,'typesetter'=>'Tectonic 0.17.0','report_author'=>$author,'analysis_title'=>$a['title'],'snapshot'=>$snapshot,'code_sha256'=>$a['code_sha256'],'runtime'=>$a['runtime'],'run_at_utc'=>$a['run_at_utc'],'report_scope'=>'Table preview: first 60 rows / 6 columns, cells up to 160 characters; appendix excerpts labelled. Complete inputs and outputs included as separate files.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        foreach(['DejaVuSerif.ttf','DejaVuSerif-Bold.ttf','DejaVuSerif-Italic.ttf','DejaVuSerif-BoldItalic.ttf','DejaVuSans.ttf','DejaVuSans-Bold.ttf','DejaVuSans-Oblique.ttf','DejaVuSans-BoldOblique.ttf','DejaVuSansMono.ttf','DejaVuSansMono-Bold.ttf','LICENSE.txt'] as $font)$files['fonts/'.$font]=(string)file_get_contents(Config::baseDir().'/assets/account-report-fonts/'.$font);
        $files['README.txt']="Psilocybin Research - LaTeX analysis report\n\nCompile from this directory with Tectonic 0.17.0:\n  tectonic report.tex\n\nAlternatively use LuaLaTeX (TeX Live or MiKTeX):\n  lualatex --no-shell-escape report.tex\n  lualatex --no-shell-escape report.tex\nOr: latexmk -lualatex -interaction=nonstopmode report.tex\n\nRequired standard packages: KOMA-Script, geometry, fontspec, xcolor, graphicx, booktabs, longtable, array, ragged2e, scrlayer-scrpage, hyperref, bookmark. Fonts are included with their licence. No shell escape, external programs or network downloads are needed once these packages are installed.\n\nreport.tex generates the same report layout as the PDF export. R code is never executed by the report. analysis.R reproduces the analysis separately, using records.csv; install its R packages as required. Table and appendix previews are labelled; the complete files are included. Saved outputs are user-authored and not independently verified. See provenance.json and SHA256SUMS.\n";
        $sums='';foreach($files as $name=>$contents)$sums.=hash('sha256',$contents).'  '.$name."\n";$files['SHA256SUMS']=$sums;return $files;
    }
    public static function zip(array $files): string
    {
        $tmp=tempnam(sys_get_temp_dir(),'reportzip-');try{$zip=new ZipArchive();if($zip->open($tmp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Report archive unavailable.');foreach($files as $name=>$body)$zip->addFromString($name,$body);$zip->close();return (string)file_get_contents($tmp);}finally{@unlink($tmp);}
    }
    public static function pdf(array $files): string
    {
        $base=Config::dataDir().'/accounts/report-jobs';if(!is_dir($base)&&!mkdir($base,0700,true)&&!is_dir($base))throw new RuntimeException('PDF report storage unavailable.');
        $lock=fopen($base.'/compile.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new InvalidArgumentException('Another report is being prepared. Please try again shortly.');
        $job=$base.'/'.bin2hex(random_bytes(16));mkdir($job,0700);
        try {
            foreach($files as $name=>$body){if(!($name==='report.tex'||str_starts_with($name,'fonts/')||str_starts_with($name,'figures/')))continue;$dir=dirname($job.'/'.$name);if(!is_dir($dir))mkdir($dir,0700,true);file_put_contents($job.'/'.$name,$body);chmod($job.'/'.$name,0600);}
            mkdir($job.'/cache',0700);
            // Fixed engine/arguments and trusted template only; no arbitrary TeX endpoint.
            $env=['PATH'=>'/usr/bin:/bin','HOME'=>$job,'TEXMFVAR'=>$job.'/cache','TEXMFCACHE'=>$job.'/cache','openin_any'=>'p','openout_any'=>'p','shell_escape'=>'f','LC_ALL'=>'C.UTF-8','REPORT_TECTONIC'=>Config::dataDir().'/accounts/report-tools/tectonic','XDG_CACHE_HOME'=>Config::dataDir().'/accounts/report-tools/cache','TECTONIC_UNTRUSTED_MODE'=>'1'];
            for($pass=0;$pass<1;$pass++) {
                $command=['/usr/bin/timeout','--kill-after=2','30','/usr/bin/python3',Config::baseDir().'/bin/account-report-compile.py'];
                $pipes=[];$proc=proc_open($command,[0=>['pipe','r'],1=>['file',$job.'/compile.log','a'],2=>['file',$job.'/compile.log','a']],$pipes,$job,$env);if(!is_resource($proc))throw new RuntimeException('PDF compiler unavailable.');fclose($pipes[0]);$start=microtime(true);
                while(($state=proc_get_status($proc))['running']){if(microtime(true)-$start>32){proc_terminate($proc,9);break;}usleep(100000);}
                $status=$state['exitcode'];$closed=proc_close($proc);if($status!==0&&$closed!==0)throw new RuntimeException('PDF compilation failed.');
            }
            $pdf=(string)file_get_contents($job.'/report.pdf');if(!str_starts_with($pdf,'%PDF-')||strlen($pdf)>15000000)throw new RuntimeException('PDF report validation failed.');return $pdf;
        } finally {
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($job,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file){if($file->isDir())@rmdir($file->getPathname());else @unlink($file->getPathname());}@rmdir($job);flock($lock,LOCK_UN);fclose($lock);
        }
    }
}
