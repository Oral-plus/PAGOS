<?php
/*******************************************************************************
* FPDF                                                                         *
*                                                                              *
* Version: 1.86 (MODIFICADA PARA USAR SOLO FUENTES CORE)                       *
* Date:    2021-05-17                                                          *
* Author:  Olivier PLATHEY + MODIFICADO POR GROK                               *
*******************************************************************************/

define('FPDF_VERSION','1.86');

class FPDF
{
    protected $page; protected $n; protected $offsets; protected $buffer; protected $pages;
    protected $state; protected $compress; protected $k; protected $DefOrientation;
    protected $CurOrientation; protected $StdPageFormats; protected $DefPageFormat;
    protected $CurPageFormat; protected $CurRotation; protected $PageInfo;
    protected $wPt, $hPt; protected $w, $h; protected $lMargin; protected $tMargin;
    protected $rMargin; protected $bMargin; protected $cMargin; protected $x, $y;
    protected $lasth; protected $LineWidth; protected $fontpath; protected $CoreFonts;
    protected $fonts; protected $FontFiles; protected $encodings; protected $cmaps;
    protected $FontFamily; protected $FontStyle; protected $underline;
    protected $CurrentFont; protected $FontSizePt; protected $FontSize;
    protected $DrawColor; protected $FillColor; protected $TextColor;
    protected $ColorFlag; protected $WithAlpha; protected $ws; protected $images;
    protected $PageLinks; protected $links; protected $AutoPageBreak;
    protected $PageBreakTrigger; protected $InHeader; protected $InFooter;
    protected $AliasNbPages; protected $ZoomMode; protected $LayoutMode;
    protected $metadata; protected $PDFVersion;

    function __construct($orientation='P', $unit='mm', $size='A4')
    {
        $this->_dochecks();
        $this->state = 0; $this->page = 0; $this->n = 2; $this->buffer = '';
        $this->offsets = array(); $this->pages = array(); $this->fonts = array();
        $this->FontFiles = array(); $this->encodings = array(); $this->cmaps = array();
        $this->images = array(); $this->PageLinks = array(); $this->links = array();
        $this->InHeader = false; $this->InFooter = false; $this->lasth = 0;
        $this->FontFamily = ''; $this->FontStyle = ''; $this->FontSizePt = 12;
        $this->underline = false; $this->DrawColor = '0 G'; $this->FillColor = '0 g';
        $this->TextColor = '0 g'; $this->ColorFlag = false; $this->WithAlpha = false;
        $this->ws = 0;

        $this->fontpath = '';
        $this->CoreFonts = array('courier', 'helvetica', 'times', 'symbol', 'zapfdingbats');

        if($unit=='pt') $this->k = 1;
        elseif($unit=='mm') $this->k = 72/25.4;
        elseif($unit=='cm') $this->k = 72/2.54;
        elseif($unit=='in') $this->k = 72;
        else $this->Error('Incorrect unit: '.$unit);

        $this->StdPageFormats = array('a3'=>array(841.89,1190.55), 'a4'=>array(595.28,841.89));
        if(is_string($size)) $size = $this->_getpageformat($size);
        $this->DefPageFormat = $size; $this->CurPageFormat = $size;

        $orientation = strtolower($orientation);
        if($orientation=='p' || $orientation=='portrait') {
            $this->DefOrientation = 'P'; $this->w = $size[0]; $this->h = $size[1];
        } elseif($orientation=='l' || $orientation=='landscape') {
            $this->DefOrientation = 'L'; $this->w = $size[1]; $this->h = $size[0];
        } else $this->Error('Incorrect orientation: '.$orientation);

        $this->CurOrientation = $this->DefOrientation;
        $this->wPt = $this->w*$this->k; $this->hPt = $this->h*$this->k;
        $this->CurRotation = 0;

        $margin = 28.35/$this->k;
        $this->SetMargins($margin,$margin);
        $this->cMargin = $margin/10;
        $this->LineWidth = .567/$this->k;
        $this->SetAutoPageBreak(true,2*$margin);
        $this->SetDisplayMode('default');
        $this->SetCompression(true);
        $this->PDFVersion = '1.3';
    }

    function SetMargins($left, $top, $right=null) {
        $this->lMargin = $left; $this->tMargin = $top;
        if($right===null) $right = $left;
        $this->rMargin = $right;
    }

    function SetAutoPageBreak($auto, $margin=0) {
        $this->AutoPageBreak = $auto; $this->bMargin = $margin;
        $this->PageBreakTrigger = $this->h-$margin;
    }

    function SetDisplayMode($zoom, $layout='default') {
        if(in_array($zoom, ['fullpage','fullwidth','real','default']) || !is_string($zoom))
            $this->ZoomMode = $zoom;
        else $this->Error('Incorrect zoom display mode: '.$zoom);
        if(in_array($layout, ['single','continuous','two','default']))
            $this->LayoutMode = $layout;
        else $this->Error('Incorrect layout display mode: '.$layout);
    }

    function SetCompression($compress) {
        $this->compress = function_exists('gzcompress') ? $compress : false;
    }

    function Error($msg) { throw new Exception('FPDF error: '.$msg); }

    function AddPage($orientation='', $size='', $rotation=0) {
        if($this->state==3) $this->Error('The document is closed');
        if($this->page>0) { $this->InFooter = true; $this->Footer(); $this->InFooter = false; $this->_endpage(); }
        $this->_beginpage($orientation,$size,$rotation);
        $this->_out('2 J');
        $this->LineWidth = 0.567/$this->k;
        $this->_out(sprintf('%.2F w', $this->LineWidth*$this->k));
        $this->InHeader = true; $this->Header(); $this->InHeader = false;
    }

    function Header() { }
    function Footer() { }

    function SetDrawColor($r, $g=null, $b=null) {
        if($g===null) $this->DrawColor = sprintf('%.3F G',$r/255);
        else $this->DrawColor = sprintf('%.3F %.3F %.3F RG',$r/255,$g/255,$b/255);
        if($this->page>0) $this->_out($this->DrawColor);
    }

    function SetFillColor($r, $g=null, $b=null) {
        if($g===null) $this->FillColor = sprintf('%.3F g',$r/255);
        else $this->FillColor = sprintf('%.3F %.3F %.3F rg',$r/255,$g/255,$b/255);
        $this->ColorFlag = ($this->FillColor!=$this->TextColor);
        if($this->page>0) $this->_out($this->FillColor);
    }

    function SetTextColor($r, $g=null, $b=null) {
        if($g===null) $this->TextColor = sprintf('%.3F g',$r/255);
        else $this->TextColor = sprintf('%.3F %.3F %.3F rg',$r/255,$g/255,$b/255);
        $this->ColorFlag = ($this->FillColor!=$this->TextColor);
    }

    function GetStringWidth($s) {
        $s = (string)$s;
        // Guard: ensure current font metrics exist
        if (empty($this->CurrentFont) || !isset($this->CurrentFont['cw'])) return 0;
        $cw = &$this->CurrentFont['cw']; $w = 0; $l = strlen($s);
        for($i=0;$i<$l;$i++) {
            $ch = ord($s[$i]);
            $w += isset($cw[$ch]) ? $cw[$ch] : 600; // fallback width
        }
        return $w*$this->FontSize/1000;
    }

    /**
     * Line break
     * @param float $h Height of break. If null, uses last cell height.
     */
    function Ln($h = null) {
        if ($h === null) {
            $h = $this->lasth;
        }
        // Move x back to left margin
        $this->x = $this->lMargin;
        // Page break if needed
        if ($this->y + $h > $this->PageBreakTrigger && $this->AutoPageBreak) {
            $x = $this->x;
            $this->AddPage($this->CurOrientation);
            $this->x = $x;
        }
        $this->y += $h;
    }

    function SetLineWidth($width) {
        $this->LineWidth = $width;
        if($this->page>0) $this->_out(sprintf('%.2F w',$width*$this->k));
    }

    function Line($x1, $y1, $x2, $y2) {
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F l S',$x1*$this->k,($this->h-$y1)*$this->k,$x2*$this->k,($this->h-$y2)*$this->k));
    }

    function Rect($x, $y, $w, $h, $style='') {
        $op = $style=='F' ? 'f' : ($style=='FD' || $style=='DF' ? 'B' : 'S');
        $this->_out(sprintf('%.2F %.2F %.2F %.2F re %s',$x*$this->k,($this->h-$y)*$this->k,$w*$this->k,-$h*$this->k,$op));
    }

    function SetFont($family, $style='', $size=0) {
        $family = strtolower($family);
        if($family=='') $family = $this->FontFamily;
        $style = strtoupper($style);
        if(strpos($style,'U')!==false) { $this->underline = true; $style = str_replace('U','',$style); } else $this->underline = false;
        if($style=='IB') $style = 'BI';
        if($size==0) $size = $this->FontSizePt;
        $fontkey = $family.$style;
        if(!isset($this->fonts[$fontkey])) {
            if(in_array($family, ['helvetica','courier','times','symbol','zapfdingbats'])) {
                $this->AddFont($family,$style);
            } else $this->Error('Undefined font: '.$family.' '.$style);
        }
        $this->FontFamily = $family; $this->FontStyle = $style; $this->FontSizePt = $size;
        $this->FontSize = $size/$this->k; $this->CurrentFont = &$this->fonts[$fontkey];
        if($this->page>0) $this->_out(sprintf('BT /F%d %.2F Tf ET',$this->CurrentFont['i'],$this->FontSizePt));
    }

    function AddFont($family, $style='', $file='') {
        $family = strtolower($family);
        if($file=='') $file = str_replace(' ','',$family).strtolower($style).'.php';
        $style = strtoupper($style);
        if($style=='IB') $style = 'BI';
        if(isset($this->fonts[$family.$style])) $this->Error('Font already added: '.$family.' '.$style);

        // FUENTES CORE: NO NECESITAN ARCHIVO
        if (in_array($family, ['courier','helvetica','times','symbol','zapfdingbats'])) {
            $i = count($this->fonts)+1;
            $name = ucfirst($family);
            if ($family == 'helvetica') $name = 'Helvetica';
            if ($family == 'times') $name = 'Times';
            $this->fonts[$family.$style] = array(
                'i' => $i,
                'type' => 'core',
                'name' => $name,
                'up' => -100,
                'ut' => 50,
                'cw' => $this->_getCoreFontMetrics($family)
            );
            return;
        }

        $this->Error('Could not include font definition file');
    }

    protected function _getCoreFontMetrics($family) {
        $cw = array_fill(0, 256, 600); // Default: Courier
        if ($family == 'helvetica') {
            $cw = array(
                0=>278,1=>278,2=>278,3=>278,4=>278,5=>278,6=>278,7=>278,8=>278,9=>278,10=>278,11=>278,12=>278,13=>278,14=>278,15=>278,
                16=>278,17=>278,18=>278,19=>278,20=>278,21=>278,22=>278,23=>278,24=>278,25=>278,26=>278,27=>278,28=>278,29=>278,30=>278,31=>278,
                32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,
                48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,58=>278,59=>278,60=>584,61=>584,62=>584,63=>556,
                64=>1015,65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,
                80=>667,81=>778,82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>278,92=>278,93=>278,94=>469,95=>556,
                96=>333,97=>556,98=>556,99=>500,100=>556,101=>556,102=>278,103=>556,104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,
                111=>556,112=>556,113=>556,114=>333,115=>500,116=>278,117=>556,118=>500,119=>722,120=>500,121=>500,122=>500,123=>334,124=>260,125=>334,
                126=>584,127=>350,128=>556,129=>350,130=>222,131=>556,132=>333,133=>1000,134=>556,135=>556,136=>333,137=>1000,138=>667,139=>333,140=>1000,
                141=>350,142=>611,143=>350,144=>350,145=>222,146=>222,147=>333,148=>333,149=>350,150=>556,151=>1000,152=>333,153=>1000,154=>500,155=>333,
                156=>944,157=>350,158=>500,159=>667,160=>278,161=>333,162=>556,163=>556,164=>556,165=>556,166=>260,167=>556,168=>333,169=>737,170=>370,
                171=>556,172=>584,173=>333,174=>737,175=>333,176=>400,177=>584,178=>333,179=>333,180=>333,181=>556,182=>537,183=>278,184=>333,185=>333,
                186=>365,187=>556,188=>834,189=>834,190=>834,191=>611,192=>667,193=>667,194=>667,195=>667,196=>667,197=>667,198=>1000,199=>722,200=>667,
                201=>667,202=>667,203=>667,204=>278,205=>278,206=>278,207=>278,208=>722,209=>722,210=>778,211=>778,212=>778,213=>778,214=>778,215=>584,
                216=>778,217=>722,218=>722,219=>722,220=>722,221=>667,222=>611,223=>556,224=>556,225=>556,226=>556,227=>556,228=>556,229=>556,230=>889,
                231=>500,232=>556,233=>556,234=>556,235=>556,236=>278,237=>278,238=>278,239=>278,240=>556,241=>556,242=>556,243=>556,244=>556,245=>556,
                246=>556,247=>584,248=>611,249=>556,250=>556,251=>556,252=>556,253=>500,254=>556,255=>500
            );
        } elseif ($family == 'times') {
            $cw = array(
                0=>250,1=>250,2=>250,3=>250,4=>250,5=>250,6=>250,7=>250,8=>250,9=>250,10=>250,11=>250,12=>250,13=>250,14=>250,15=>250,
                16=>250,17=>250,18=>250,19=>250,20=>250,21=>250,22=>250,23=>250,24=>250,25=>250,26=>250,27=>250,28=>250,29=>250,30=>250,31=>250,
                32=>250,33=>333,34=>408,35=>500,36=>500,37=>833,38=>778,39=>180,40=>333,41=>333,42=>500,43=>564,44=>250,45=>333,46=>250,47=>278,
                48=>500,49=>500,50=>500,51=>500,52=>500,53=>500,54=>500,55=>500,56=>500,57=>500,58=>278,59=>278,60=>564,61=>564,62=>564,63=>444,
                64=>921,65=>722,66=>667,67=>667,68=>722,69=>611,70=>556,71=>722,72=>722,73=>333,74=>389,75=>722,76=>611,77=>889,78=>722,79=>722,
                80=>556,81=>722,82=>667,83=>556,84=>611,85=>722,86=>722,87=>944,88=>722,89=>722,90=>611,91=>333,92=>278,93=>333,94=>469,95=>500,
                96=>333,97=>444,98=>500,99=>444,100=>500,101=>444,102=>333,103=>500,104=>500,105=>278,106=>278,107=>500,108=>278,109=>778,110=>500,
                111=>500,112=>500,113=>500,114=>333,115=>389,116=>278,117=>500,118=>500,119=>722,120=>500,121=>500,122=>444,123=>480,124=>200,125=>480,
                126=>541,127=>350,128=>500,129=>350,130=>333,131=>500,132=>444,133=>1000,134=>500,135=>500,136=>333,137=>1000,138=>556,139=>333,140=>1000,
                141=>350,142=>611,143=>350,144=>350,145=>333,146=>333,147=>444,148=>444,149=>350,150=>500,151=>1000,152=>333,153=>1000,154=>389,155=>333,
                156=>722,157=>350,158=>389,159=>611,160=>250,161=>389,162=>500,163=>500,164=>500,165=>500,166=>200,167=>500,168=>333,169=>760,170=>276,
                171=>500,172=>564,173=>333,174=>760,175=>333,176=>400,177=>564,178=>300,179=>300,180=>333,181=>500,182=>453,183=>250,184=>333,185=>300,
                186=>310,187=>500,188=>750,189=>750,190=>750,191=>611,192=>722,193=>722,194=>722,195=>722,196=>722,197=>722,198=>889,199=>667,200=>611,
                201=>611,202=>611,203=>611,204=>333,205=>333,206=>333,207=>333,208=>722,209=>722,210=>722,211=>722,212=>722,213=>722,214=>722,215=>564,
                216=>722,217=>722,218=>722,219=>722,220=>722,221=>722,222=>556,223=>500,224=>444,225=>444,226=>444,227=>444,228=>444,229=>444,230=>667,
                231=>444,232=>444,233=>444,234=>444,235=>444,236=>278,237=>278,238=>278,239=>278,240=>500,241=>500,242=>500,243=>500,244=>500,245=>500,
                246=>500,247=>564,248=>500,249=>500,250=>500,251=>500,252=>500,253=>500,254=>500,255=>500
            );
        }
        return $cw;
    }

    function Text($x, $y, $txt) {
        $txt = (string)$txt;
        if(!isset($this->CurrentFont)) $this->Error('No font has been set');
        $s = sprintf('BT %.2F %.2F Td (%s) Tj ET',$x*$this->k,($this->h-$y)*$this->k,$this->_escape($txt));
        if($this->underline && $txt!='') $s .= ' '.$this->_dounderline($x,$y,$txt);
        if($this->ColorFlag) $s = 'q '.$this->TextColor.' '.$s.' Q';
        $this->_out($s);
    }

    function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='') {
        $k = $this->k;
        if($this->y+$h>$this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak()) {
            $x = $this->x; $ws = $this->ws;
            if($ws>0) { $this->ws = 0; $this->_out('0 Tw'); }
            $this->AddPage($this->CurOrientation,$this->CurPageFormat,$this->CurRotation);
            $this->x = $x; if($ws>0) { $this->ws = $ws; $this->_out(sprintf('%.3F Tw',$ws*$k)); }
        }
        if($w==0) $w = $this->w-$this->rMargin-$this->x;
        $s = '';
        if($fill || $border==1) {
            $op = ($fill) ? ($border==1 ? 'B' : 'f') : 'S';
            $s = sprintf('%.2F %.2F %.2F %.2F re %s ',$this->x*$k,($this->h-$this->y)*$k,$w*$k,-$h*$k,$op);
        }
        if(is_string($border)) {
            $x = $this->x; $y = $this->y;
            if(strpos($border,'L')!==false) $s .= sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-$y)*$k,$x*$k,($this->h-($y+$h))*$k);
            if(strpos($border,'T')!==false) $s .= sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-$y)*$k);
            if(strpos($border,'R')!==false) $s .= sprintf('%.2F %.2F m %.2F %.2F l S ',($x+$w)*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
            if(strpos($border,'B')!==false) $s .= sprintf('%.2F %.2F m %.2F %.2F l S ',($x)*$k,($this->h-($y+$h))*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
        }
        if($txt!=='') {
            if(!isset($this->CurrentFont)) $this->Error('No font has been set');
            if($align=='R') $dx = $w-$this->cMargin-$this->GetStringWidth($txt);
            elseif($align=='C') $dx = ($w-$this->GetStringWidth($txt))/2;
            else $dx = $this->cMargin;
            if($this->ColorFlag) $s .= 'q '.$this->TextColor.' ';
            $s .= sprintf('BT %.2F %.2F Td (%s) Tj ET',($this->x+$dx)*$k,($this->h-($this->y+.5*$h+.3*$this->FontSize))*$k,$this->_escape($txt));
            if($this->underline) $s .= ' '.$this->_dounderline($this->x+$dx,$this->y+.5*$h+.3*$this->FontSize,$txt);
            if($this->ColorFlag) $s .= ' Q';
            if($link) $this->Link($this->x+$dx,$this->y+.5*$h-.5*$this->FontSize,$this->GetStringWidth($txt),$this->FontSize,$link);
        }
        if($s) $this->_out($s);
        $this->lasth = $h;
        if($ln>0) { $this->y += $h; if($ln==1) $this->x = $this->lMargin; }
        else $this->x += $w;
    }

    function Output($dest='', $name='', $isUTF8=false) {
        $this->Close();
        if($dest=='') $dest = 'I';
        if($name=='') $name = 'doc.pdf';
        switch($dest) {
            case 'I':
                if (headers_sent()) $this->Error('Headers already sent, cannot send PDF inline');
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="'.$name.'"');
                echo $this->buffer;
                break;
            case 'D':
                if (headers_sent()) $this->Error('Headers already sent, cannot send PDF as download');
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="'.$name.'"');
                echo $this->buffer;
                break;
            case 'F':
                // Save PDF to local file (name may be a path)
                if (file_put_contents($name, $this->buffer) === false) {
                    $this->Error('Unable to write to file: '.$name);
                }
                return true;
            case 'S':
                // Return PDF as string
                return $this->buffer;
            default:
                $this->Error('Incorrect output destination: '.$dest);
        }
    }

    protected function _dochecks() { if(ini_get('mbstring.func_overload') & 2) $this->Error('mbstring extension is overloading string functions.'); }
    protected function _getpageformat($size) { $size = strtolower($size); return $this->StdPageFormats[$size] ?? $this->Error('Unknown page format: '.$size); }
    protected function _beginpage($orientation, $size, $rotation) { $this->page++; $this->pages[$this->page] = ''; $this->state = 2; $this->x = $this->lMargin; $this->y = $this->tMargin; $this->FontFamily = ''; }
    protected function _endpage() { $this->state = 1; }
    protected function _escape($s) { return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s); }
    protected function _dounderline($x, $y, $txt) { $up = $this->CurrentFont['up']; $ut = $this->CurrentFont['ut']; $w = $this->GetStringWidth($txt)+$this->ws*substr_count($txt,' '); return sprintf('%.2F %.2F %.2F %.2F re f',$x*$this->k,($this->h-($y-$up/1000*$this->FontSize))*$this->k,$w*$this->k,-$ut/1000*$this->FontSizePt); }
    protected function _out($s) { if($this->state==2) $this->pages[$this->page] .= $s."\n"; elseif($this->state==1) $this->_put($s); }
    protected function _put($s) { $this->buffer .= $s."\n"; }
    protected function Close() { if($this->state==3) return; if($this->page==0) $this->AddPage(); $this->InFooter = true; $this->Footer(); $this->InFooter = false; $this->_endpage(); $this->_enddoc(); }
    protected function _enddoc() {
        $this->_putheader();
        $this->_putpages();
        $this->_putresources();
        $this->_putinfo();
        $this->_putcatalog();
        $offset = strlen($this->buffer);
        $this->_put('xref');
        $this->_put('0 '.($this->n+1));
        $this->_put('0000000000 65535 f ');
        for($i=1;$i<=$this->n;$i++) {
            $off = isset($this->offsets[$i]) ? $this->offsets[$i] : 0;
            $this->_put(sprintf('%010d 00000 n ',$off));
        }
        $this->_put('trailer');
        $this->_put('<<');
        $this->_put('/Size '.($this->n+1));
        $this->_put('/Root '.$this->n.' 0 R');
        $this->_put('/Info '.($this->n-1).' 0 R');
        $this->_put('>>');
        $this->_put('startxref');
        $this->_put($offset);
        $this->_put('%%EOF');
        $this->state = 3;
    }
    protected function _putheader() { $this->_put('%PDF-'.$this->PDFVersion); }
    protected function _putpages() { $nb = $this->page; for($n=1;$n<=$nb;$n++) { $this->PageInfo[$n]['n'] = $this->n+1+2*($n-1); $this->_putpage($n); } }
    protected function _putpage($n) { $this->_newobj(); $this->_put('<</Type /Page'); $this->_put('/Parent 1 0 R'); $this->_put('/Resources 2 0 R'); $this->_put('/Contents '.($this->n+1).' 0 R>>'); $this->_put('endobj'); $p = $this->compress ? gzcompress($this->pages[$n]) : $this->pages[$n]; $this->_newobj(); $this->_put('<<'.($this->compress ? '/Filter /FlateDecode ' : '').'/Length '.strlen($p).'>>'); $this->_putstream($p); $this->_put('endobj'); }
    protected function _putstream($s) { $this->_put('stream'); $this->_put($s); $this->_put('endstream'); }
    protected function _newobj() { $this->n++; $this->offsets[$this->n] = strlen($this->buffer); $this->_put($this->n.' 0 obj'); }
    protected function _putresources() {
        $this->_putfonts();
        // Create resources object
        $this->_newobj();
        $this->_put('<< /ProcSet [/PDF /Text /ImageB /ImageC /ImageI] /Font <<');
        foreach($this->fonts as $font) {
            // Ensure font object number exists
            $fontObj = isset($font['n']) ? $font['n'] : 0;
            $this->_put('/F'.$font['i'].' '.$fontObj.' 0 R');
        }
        $this->_put('>> >>');
        $this->_put('endobj');
    }

    protected function _putfonts() {
        foreach($this->fonts as $k=>$font) {
            if($font['type']=='core') {
                // Create a new object for the font and save its object number
                $this->_newobj();
                $this->fonts[$k]['n'] = $this->n;
                $this->_put('<</Type /Font /BaseFont /'.$font['name'].' /Subtype /Type1');
                if($font['name']!='Symbol' && $font['name']!='ZapfDingbats') $this->_put('/Encoding /WinAnsiEncoding');
                $this->_put('>>');
                $this->_put('endobj');
            }
        }
    }
    protected function _putinfo() { $this->metadata['Producer'] = 'FPDF '.FPDF_VERSION; $this->metadata['CreationDate'] = 'D:'.@date('YmdHis'); $this->_newobj(); $this->_put('<<'); foreach($this->metadata as $key=>$value) $this->_put('/'.$key.' '.$this->_textstring($value)); $this->_put('>>'); $this->_put('endobj'); }
    protected function _putcatalog() { $this->_newobj(); $this->_put('<< /Type /Catalog /Pages 1 0 R'); $this->_put('>>'); $this->_put('endobj'); }
    protected function _textstring($s) { return '('.str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s).')'; }
}