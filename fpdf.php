<?php
/**
 * FPDF - Librería PHP para generar PDFs
 * Versión simplificada y autónoma
 */

if(!class_exists('FPDF')) {

define('FPDF_VERSION','1.84');

class FPDF
{
    protected $page;
    protected $n;
    protected $offsets;
    protected $buffer;
    protected $pages;
    protected $state;
    protected $compress;
    protected $k;
    protected $DefOrientation;
    protected $CurOrientation;
    protected $StdPageSizes;
    protected $DefPageSize;
    protected $CurPageSize;
    protected $CurRotation;
    protected $PageInfo;
    protected $wPt, $hPt;
    protected $w, $h;
    protected $lMargin;
    protected $tMargin;
    protected $rMargin;
    protected $bMargin;
    protected $cMargin;
    protected $x, $y;
    protected $lasth;
    protected $LineWidth;
    protected $fontpath;
    protected $CoreFonts;
    protected $fonts;
    protected $FontFiles;
    protected $encodings;
    protected $cmaps;
    protected $FontFamily;
    protected $FontStyle;
    protected $underline;
    protected $CurrentFont;
    protected $FontSizePt;
    protected $FontSize;
    protected $DrawColor;
    protected $FillColor;
    protected $TextColor;
    protected $ColorFlag;
    protected $WithAlpha;
    protected $ws;
    protected $images;
    protected $PageLinks;
    protected $links;
    protected $AutoPageBreak;
    protected $PageBreakTrigger;
    protected $InHeader;
    protected $InFooter;
    protected $AliasNbPages;
    protected $ZoomMode;
    protected $LayoutMode;
    protected $metadata;
    protected $PDFVersion;

    function __construct($orientation='P', $unit='mm', $size='A4')
    {
        $this->page = 0;
        $this->n = 2;
        $this->buffer = '';
        $this->pages = array();
        $this->PageInfo = array();
        $this->fonts = array();
        $this->FontFiles = array();
        $this->encodings = array();
        $this->cmaps = array();
        $this->images = array();
        $this->links = array();
        $this->InHeader = false;
        $this->InFooter = false;
        $this->lasth = 0;
        $this->FontFamily = '';
        $this->FontStyle = '';
        $this->FontSizePt = 12;
        $this->underline = false;
        $this->DrawColor = '0 G';
        $this->FillColor = '0 g';
        $this->TextColor = '0 g';
        $this->ColorFlag = false;
        $this->WithAlpha = false;
        $this->ws = 0;
        
        $this->fontpath = dirname(__FILE__).'/font/';
        if(!file_exists($this->fontpath))
            $this->fontpath = '';
        
        $this->CoreFonts = array('courier', 'helvetica', 'times', 'symbol', 'zapfdingbats');
        
        if($unit=='pt')
            $this->k = 1;
        elseif($unit=='mm')
            $this->k = 72/25.4;
        elseif($unit=='cm')
            $this->k = 72/2.54;
        elseif($unit=='in')
            $this->k = 72;
        else
            $this->Error('Incorrect unit: '.$unit);
        
        $this->StdPageSizes = array('a3'=>array(841.89,1190.55), 'a4'=>array(595.28,841.89), 'a5'=>array(420.94,595.28),
            'letter'=>array(612,792), 'legal'=>array(612,1008));
        
        if(is_string($size))
            $size = $this->_getpagesize($size);
        $this->DefPageSize = $size;
        $this->CurPageSize = $size;
        
        $this->DefOrientation = strtoupper($orientation);
        $this->CurOrientation = $this->DefOrientation;
        
        $this->wPt = $size[0];
        $this->hPt = $size[1];
        $this->w = $size[0]/$this->k;
        $this->h = $size[1]/$this->k;
        
        $margin = 28.35/$this->k;
        $this->SetMargins($margin,$margin);
        $this->cMargin = $margin/10;
        $this->LineWidth = .567/$this->k;
        $this->SetAutoPageBreak(true,2*$margin);
        $this->SetDisplayMode('default');
        $this->SetCompression(true);
        $this->PDFVersion = '1.3';
    }

    function SetMargins($left, $top, $right=null)
    {
        $this->lMargin = $left;
        $this->tMargin = $top;
        if($right===null)
            $right = $left;
        $this->rMargin = $right;
    }

    function SetLeftMargin($margin)
    {
        $this->lMargin = $margin;
        if($this->page>0 && $this->x<$margin)
            $this->x = $margin;
    }

    function SetTopMargin($margin)
    {
        $this->tMargin = $margin;
    }

    function SetRightMargin($margin)
    {
        $this->rMargin = $margin;
    }

    function SetAutoPageBreak($auto, $margin=0)
    {
        $this->AutoPageBreak = $auto;
        $this->bMargin = $margin;
        $this->PageBreakTrigger = $this->h-$margin;
    }

    function SetDisplayMode($zoom, $layout='default')
    {
        if($zoom=='fullpage' || $zoom=='fullwidth' || $zoom=='real' || $zoom=='default' || !is_string($zoom))
            $this->ZoomMode = $zoom;
        else
            $this->Error('Incorrect zoom display mode: '.$zoom);
        if($layout=='single' || $layout=='continuous' || $layout=='two' || $layout=='default')
            $this->LayoutMode = $layout;
        else
            $this->Error('Incorrect layout display mode: '.$layout);
    }

    function SetCompression($compress)
    {
        $this->compress = $compress;
    }

    function SetTitle($title, $isUTF8=false)
    {
        $this->metadata['Title'] = $isUTF8 ? $title : $this->_UTF8encode($title);
    }

    function SetAuthor($author, $isUTF8=false)
    {
        $this->metadata['Author'] = $isUTF8 ? $author : $this->_UTF8encode($author);
    }

    function SetSubject($subject, $isUTF8=false)
    {
        $this->metadata['Subject'] = $isUTF8 ? $subject : $this->_UTF8encode($subject);
    }

    function SetKeywords($keywords, $isUTF8=false)
    {
        $this->metadata['Keywords'] = $isUTF8 ? $keywords : $this->_UTF8encode($keywords);
    }

    function SetCreator($creator, $isUTF8=false)
    {
        $this->metadata['Creator'] = $isUTF8 ? $creator : $this->_UTF8encode($creator);
    }

    function AliasNbPages($alias='{nb}')
    {
        $this->AliasNbPages = $alias;
    }

    function Error($msg)
    {
        throw new Exception('FPDF error: '.$msg);
    }

    function Close()
    {
        if($this->state==3)
            return;
        if($this->page==0)
            $this->AddPage();
        $this->InFooter = true;
        $this->Footer();
        $this->InFooter = false;
        $this->_endpage();
        $this->_enddoc();
    }

    function AddPage($orientation='', $size='', $rotation=0)
    {
        if($this->state==3)
            $this->Error('The document is closed');
        $family = $this->FontFamily;
        $style = $this->FontStyle.($this->underline ? 'U' : '');
        $fontsize = $this->FontSizePt;
        $lw = $this->LineWidth;
        $dc = $this->DrawColor;
        $fc = $this->FillColor;
        $tc = $this->TextColor;
        $cf = $this->ColorFlag;
        if($this->page>0)
        {
            $this->InFooter = true;
            $this->Footer();
            $this->InFooter = false;
            $this->_endpage();
        }
        $this->_beginpage($orientation,$size,$rotation);
        $this->_out('2 J');
        $this->LineWidth = $lw;
        $this->_out(sprintf('%.2F w',$lw*$this->k));
        if($family)
            $this->SetFont($family,$style,$fontsize);
        $this->DrawColor = $dc;
        if($dc!='0 G')
            $this->_out($dc);
        $this->FillColor = $fc;
        if($fc!='0 g')
            $this->_out($fc);
        $this->TextColor = $tc;
        $this->ColorFlag = $cf;
        $this->InHeader = true;
        $this->Header();
        $this->InHeader = false;
        if($this->LineWidth!=$lw)
        {
            $this->LineWidth = $lw;
            $this->_out(sprintf('%.2F w',$lw*$this->k));
        }
        if($family)
            $this->SetFont($family,$style,$fontsize);
        if($this->DrawColor!=$dc)
        {
            $this->DrawColor = $dc;
            $this->_out($dc);
        }
        if($this->FillColor!=$fc)
        {
            $this->FillColor = $fc;
            $this->_out($fc);
        }
        $this->TextColor = $tc;
        $this->ColorFlag = $cf;
    }

    function Header()
    {
    }

    function Footer()
    {
    }

    function PageNo()
    {
        return $this->page;
    }

    function SetDrawColor($r, $g=null, $b=null)
    {
        if(($r==0 && $g==0 && $b==0) || $g===null)
            $this->DrawColor = sprintf('%.3F G',$r/255);
        else
            $this->DrawColor = sprintf('%.3F %.3F %.3F RG',$r/255,$g/255,$b/255);
        if($this->page>0)
            $this->_out($this->DrawColor);
    }

    function SetFillColor($r, $g=null, $b=null)
    {
        if(($r==0 && $g==0 && $b==0) || $g===null)
            $this->FillColor = sprintf('%.3F g',$r/255);
        else
            $this->FillColor = sprintf('%.3F %.3F %.3F rg',$r/255,$g/255,$b/255);
        $this->ColorFlag = ($this->FillColor!=$this->TextColor);
        if($this->page>0)
            $this->_out($this->FillColor);
    }

    function SetTextColor($r, $g=null, $b=null)
    {
        if(($r==0 && $g==0 && $b==0) || $g===null)
            $this->TextColor = sprintf('%.3F g',$r/255);
        else
            $this->TextColor = sprintf('%.3F %.3F %.3F rg',$r/255,$g/255,$b/255);
        $this->ColorFlag = ($this->FillColor!=$this->TextColor);
    }

    function GetStringWidth($s)
    {
        $s = (string)$s;
        $cw = &$this->CurrentFont['cw'];
        $w = 0;
        $l = strlen($s);
        for($i=0;$i<$l;$i++)
            $w += $cw[$s[$i]];
        return $w*$this->FontSize/1000;
    }

    function SetLineWidth($width)
    {
        $this->LineWidth = $width;
        if($this->page>0)
            $this->_out(sprintf('%.2F w',$width*$this->k));
    }

    function Line($x1, $y1, $x2, $y2)
    {
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F l S',$x1*$this->k,($this->h-$y1)*$this->k,$x2*$this->k,($this->h-$y2)*$this->k));
    }

    function Rect($x, $y, $w, $h, $style='')
    {
        if($style=='F')
            $op = 'f';
        elseif($style=='FD' || $style=='DF')
            $op = 'B';
        else
            $op = 'S';
        $this->_out(sprintf('%.2F %.2F %.2F %.2F re %s',$x*$this->k,($this->h-$y)*$this->k,$w*$this->k,-$h*$this->k,$op));
    }

    function AddFont($family, $style='', $file='')
    {
        $family = strtolower($family);
        if($file=='')
            $file = str_replace(' ','',$family).strtolower($style).'.php';
        $style = strtoupper($style);
        if($style=='IB')
            $style = 'BI';
        $fontkey = $family.$style;
        if(isset($this->fonts[$fontkey]))
            return;
        
        if(file_exists($this->fontpath.$file))
        {
            include($this->fontpath.$file);
        }
        else
        {
            if(!isset($name))
                $name = $family;
            if(!isset($cw))
                $cw = array();
        }
        
        $i = count($this->fonts)+1;
        $this->fonts[$fontkey] = array('i'=>$i, 'type'=>'core', 'name'=>$name, 'up'=>-100, 'ut'=>50, 'cw'=>$cw);
    }

    function SetFont($family, $style='', $size=0)
    {
        if($family=='')
            $family = $this->FontFamily;
        else
            $family = strtolower($family);
        $style = strtoupper($style);
        if(strpos($style,'U')!==false)
        {
            $this->underline = true;
            $style = str_replace('U','',$style);
        }
        else
            $this->underline = false;
        if($style=='IB')
            $style = 'BI';
        if($size==0)
            $size = $this->FontSizePt;
        
        if($this->FontFamily==$family && $this->FontStyle==$style && $this->FontSizePt==$size)
            return;
        
        $fontkey = $family.$style;
        if(!isset($this->fonts[$fontkey]))
        {
            if(in_array($family,$this->CoreFonts))
            {
                if($family=='symbol' || $family=='zapfdingbats')
                    $style = '';
                $fontkey = $family.$style;
                if(!isset($this->fonts[$fontkey]))
                    $this->AddFont($family,$style);
            }
            else
                $this->Error('Undefined font: '.$family.' '.$style);
        }
        
        $this->FontFamily = $family;
        $this->FontStyle = $style;
        $this->FontSizePt = $size;
        $this->FontSize = $size/$this->k;
        $this->CurrentFont = &$this->fonts[$fontkey];
        if($this->page>0)
            $this->_out(sprintf('BT /F%d %.2F Tf ET',$this->CurrentFont['i'],$this->FontSizePt));
    }

    function SetFontSize($size)
    {
        if($this->FontSizePt==$size)
            return;
        $this->FontSizePt = $size;
        $this->FontSize = $size/$this->k;
        if($this->page>0)
            $this->_out(sprintf('BT /F%d %.2F Tf ET',$this->CurrentFont['i'],$this->FontSizePt));
    }

    function AddLink()
    {
        $n = count($this->links)+1;
        $this->links[$n] = array(0, 0);
        return $n;
    }

    function SetLink($link, $y=0, $page=-1)
    {
        if($y==-1)
            $y = $this->y;
        if($page==-1)
            $page = $this->page;
        $this->links[$link] = array($page, $y);
    }

    function Link($x, $y, $w, $h, $link)
    {
        $this->PageLinks[$this->page][] = array($x*$this->k, $this->hPt-$y*$this->k, $w*$this->k, $h*$this->k, $link);
    }

    function Text($x, $y, $txt)
    {
        $s = sprintf('BT %.2F %.2F Td (%s) Tj ET',$x*$this->k,($this->h-$y)*$this->k,$this->_escape($txt));
        if($this->underline && $txt!='')
            $s .= ' '.$this->_dounderline($x,$y,$txt);
        if($this->ColorFlag)
            $s = 'q '.$this->TextColor.' '.$s.' Q';
        $this->_out($s);
    }

    function AcceptPageBreak()
    {
        return $this->AutoPageBreak;
    }

    function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='')
    {
        $k = $this->k;
        if($this->y+$h>$this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak())
        {
            $x = $this->x;
            $ws = $this->ws;
            if($ws>0)
            {
                $this->ws = 0;
                $this->_out('0 Tw');
            }
            $this->AddPage($this->CurOrientation,$this->CurPageSize,$this->CurRotation);
            $this->x = $x;
            if($ws>0)
            {
                $this->ws = $ws;
                $this->_out(sprintf('%.3F Tw',$ws*$k));
            }
        }
        if($w==0)
            $w = $this->w-$this->rMargin-$this->x;
        $s = '';
        if($fill || $border==1)
        {
            if($fill)
                $op = ($border==1) ? 'B' : 'f';
            else
                $op = 'S';
            $s = sprintf('%.2F %.2F %.2F %.2F re %s ',$this->x*$k,($this->h-$this->y)*$k,$w*$k,-$h*$k,$op);
        }
        if(is_string($border))
        {
            $x = $this->x;
            $y = $this->y;
            if(strpos($border,'L')!==false)
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-$y)*$k,$x*$k,($this->h-($y+$h))*$k);
            if(strpos($border,'T')!==false)
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-$y)*$k);
            if(strpos($border,'R')!==false)
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ',($x+$w)*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
            if(strpos($border,'B')!==false)
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-($y+$h))*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
        }
        if($txt!=='')
        {
            if(!isset($this->CurrentFont))
                $this->Error('No font has been set');
            if($align=='R')
                $dx = $w-$this->cMargin-$this->GetStringWidth($txt);
            elseif($align=='C')
                $dx = ($w-$this->GetStringWidth($txt))/2;
            else
                $dx = $this->cMargin;
            if($this->ColorFlag)
                $s .= 'q '.$this->TextColor.' ';
            $s .= sprintf('BT %.2F %.2F Td (%s) Tj ET',($this->x+$dx)*$k,($this->h-($this->y+.5*$h+.3*$this->FontSize))*$k,$this->_escape($txt));
            if($this->underline)
                $s .= ' '.$this->_dounderline($this->x+$dx,$this->y+.5*$h+.3*$this->FontSize,$txt);
            if($this->ColorFlag)
                $s .= ' Q';
            if($link)
                $this->Link($this->x+$dx,$this->y+.5*$h-.5*$this->FontSize,$this->GetStringWidth($txt),$this->FontSize,$link);
        }
        if($s)
            $this->_out($s);
        $this->lasth = $h;
        if($ln>0)
        {
            $this->y += $h;
            if($ln==1)
                $this->x = $this->lMargin;
        }
        else
            $this->x += $w;
    }

    function MultiCell($w, $h, $txt, $border=0, $align='J', $fill=false)
    {
        if(!isset($this->CurrentFont))
            $this->Error('No font has been set');
        $cw = &$this->CurrentFont['cw'];
        if($w==0)
            $w = $this->w-$this->rMargin-$this->x;
        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
        $s = str_replace("\r",'',(string)$txt);
        $nb = strlen($s);
        if($nb>0 && $s[$nb-1]=="\n")
            $nb--;
        $b = 0;
        if($border)
        {
            if($border==1)
            {
                $border = 'LTRB';
                $b = 'LRT';
                $b2 = 'LR';
            }
            else
            {
                $b2 = '';
                if(strpos($border,'L')!==false)
                    $b2 .= 'L';
                if(strpos($border,'R')!==false)
                    $b2 .= 'R';
                $b = (strpos($border,'T')!==false) ? $b2.'T' : $b2;
            }
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $ns = 0;
        $nl = 1;
        while($i<$nb)
        {
            $c = $s[$i];
            if($c=="\n")
            {
                if($this->ws>0)
                {
                    $this->ws = 0;
                    $this->_out('0 Tw');
                }
                $this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                if($border && $nl==2)
                    $b = $b2;
                continue;
            }
            if($c==' ')
            {
                $sep = $i;
                $ls = $l;
                $ns++;
            }
            $l += $cw[$c];
            if($l>$wmax)
            {
                if($sep==-1)
                {
                    if($i==$j)
                        $i++;
                    if($this->ws>0)
                    {
                        $this->ws = 0;
                        $this->_out('0 Tw');
                    }
                    $this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
                }
                else
                {
                    if($align=='J')
                    {
                        $this->ws = ($ns>1) ? ($wmax-$ls)/1000*$this->FontSize/($ns-1) : 0;
                        $this->_out(sprintf('%.3F Tw',$this->ws*$this->k));
                    }
                    $this->Cell($w,$h,substr($s,$j,$sep-$j),$b,2,$align,$fill);
                    $i = $sep+1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                if($border && $nl==2)
                    $b = $b2;
            }
            else
                $i++;
        }
        if($this->ws>0)
        {
            $this->ws = 0;
            $this->_out('0 Tw');
        }
        if($border && strpos($border,'B')!==false)
            $b .= 'B';
        $this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
        $this->x = $this->lMargin;
    }

    function Write($h, $txt, $link='')
    {
        if(!isset($this->CurrentFont))
            $this->Error('No font has been set');
        $cw = &$this->CurrentFont['cw'];
        $w = $this->w-$this->rMargin-$this->x;
        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
        $s = str_replace("\r",'',(string)$txt);
        $nb = strlen($s);
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while($i<$nb)
        {
            $c = $s[$i];
            if($c=="\n")
            {
                $this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',false,$link);
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                if($nl==1)
                {
                    $this->x = $this->lMargin;
                    $w = $this->w-$this->rMargin-$this->x;
                    $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
                }
                $nl++;
                continue;
            }
            if($c==' ')
                $sep = $i;
            $l += $cw[$c];
            if($l>$wmax)
            {
                if($sep==-1)
                {
                    if($this->x>$this->lMargin)
                    {
                        $this->x = $this->lMargin;
                        $this->y += $h;
                        $w = $this->w-$this->rMargin-$this->x;
                        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
                        $i++;
                        $nl++;
                        continue;
                    }
                    if($i==$j)
                        $i++;
                    $this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',false,$link);
                }
                else
                {
                    $this->Cell($w,$h,substr($s,$j,$sep-$j),0,2,'',false,$link);
                    $i = $sep+1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                if($nl==1)
                {
                    $this->x = $this->lMargin;
                    $w = $this->w-$this->rMargin-$this->x;
                    $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
                }
                $nl++;
            }
            else
                $i++;
        }
        if($i!=$j)
            $this->Cell($l/1000*$this->FontSize,$h,substr($s,$j),0,0,'',false,$link);
    }

    function Ln($h=null)
    {
        $this->x = $this->lMargin;
        if($h===null)
            $this->y += $this->lasth;
        else
            $this->y += $h;
    }

    function Image($file, $x=null, $y=null, $w=0, $h=0, $type='', $link='')
    {
        if($file=='')
            $this->Error('Image file name is empty');
        if(!isset($this->images[$file]))
        {
            if($type=='')
            {
                $pos = strrpos($file,'.');
                if(!$pos)
                    $this->Error('Image file has no extension and no type was specified: '.$file);
                $type = substr($file,$pos+1);
            }
            $type = strtolower($type);
            if($type=='jpeg')
                $type = 'jpg';
            $mtd = '_parse'.$type;
            if(!method_exists($this,$mtd))
                $this->Error('Unsupported image type: '.$type);
            $info = $this->$mtd($file);
            $info['i'] = count($this->images)+1;
            $this->images[$file] = $info;
        }
        else
            $info = $this->images[$file];
        
        if($x===null)
            $x = $this->x;
        if($y===null)
            $y = $this->y;
        if($w==0 && $h==0)
        {
            $w = -96;
            $h = -96;
        }
        if($w<0)
            $w = -$info['w']*72/$w/$this->k;
        if($h<0)
            $h = -$info['h']*72/$h/$this->k;
        if($w==0)
            $w = $h*$info['w']/$info['h'];
        if($h==0)
            $h = $w*$info['h']/$info['w'];
        
        if($this->y+$h>$this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak())
        {
            $x2 = $this->x;
            $this->AddPage($this->CurOrientation,$this->CurPageSize,$this->CurRotation);
            $this->x = $x2;
        }
        
        $this->_out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q',$w*$this->k,$h*$this->k,$x*$this->k,($this->h-($y+$h))*$this->k,$info['i']));
        if($link)
            $this->Link($x,$y,$w,$h,$link);
    }

    function GetX()
    {
        return $this->x;
    }

    function SetX($x)
    {
        if($x>=0)
            $this->x = $x;
        else
            $this->x = $this->w+$x;
    }

    function GetY()
    {
        return $this->y;
    }

    function SetY($y, $resetX=true)
    {
        if($y>=0)
            $this->y = $y;
        else
            $this->y = $this->h+$y;
        if($resetX)
            $this->x = $this->lMargin;
    }

    function SetXY($x, $y)
    {
        $this->SetX($x);
        $this->SetY($y,false);
    }

    function Output($dest='', $name='', $isUTF8=false)
    {
        $this->Close();
        if(strlen($name)==1 && strlen($dest)!=1)
        {
            $tmp = $dest;
            $dest = $name;
            $name = $tmp;
        }
        if($dest=='')
            $dest = 'I';
        if($name=='')
            $name = 'doc.pdf';
        switch(strtoupper($dest))
        {
            case 'I':
                $this->_checkoutput();
                if(PHP_SAPI!='cli')
                {
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: inline; '.$this->_httpencode('filename',$name,$isUTF8));
                    header('Cache-Control: private, max-age=0, must-revalidate');
                    header('Pragma: public');
                }
                echo $this->buffer;
                break;
            case 'D':
                $this->_checkoutput();
                header('Content-Type: application/x-download');
                header('Content-Disposition: attachment; '.$this->_httpencode('filename',$name,$isUTF8));
                header('Cache-Control: private, max-age=0, must-revalidate');
                header('Pragma: public');
                echo $this->buffer;
                break;
            case 'F':
                if(!file_put_contents($name,$this->buffer))
                    $this->Error('Unable to create output file: '.$name);
                break;
            case 'S':
                return $this->buffer;
            default:
                $this->Error('Incorrect output destination: '.$dest);
        }
        return '';
    }

    protected function _dochecks()
    {
        if(PHP_VERSION<'5.1.0')
            $this->Error('This version of FPDF requires PHP 5.1.0 or above');
    }

    protected function _checkoutput()
    {
        if(PHP_SAPI!='cli')
        {
            if(headers_sent($file,$line))
                $this->Error("Some data has already been output, can't send PDF file (output started at $file:$line)");
        }
        if(ob_get_length())
        {
            if(preg_match('/^(\xEF\xBB\xBF)?\s*$/',ob_get_contents()))
            {
                ob_end_clean();
            }
            else
                $this->Error("Some data has already been output, can't send PDF file");
        }
    }

    protected function _getpagesize($size)
    {
        if(is_string($size))
        {
            $size = strtolower($size);
            if(!isset($this->StdPageSizes[$size]))
                $this->Error('Unknown page size: '.$size);
            $a = $this->StdPageSizes[$size];
            return array($a[0]/$this->k, $a[1]/$this->k);
        }
        else
        {
            if($size[0]>$size[1])
                return array($size[1], $size[0]);
            else
                return $size;
        }
    }

    protected function _beginpage($orientation, $size, $rotation)
    {
        $this->page++;
        $this->pages[$this->page] = '';
        $this->state = 2;
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
        $this->FontFamily = '';
        
        if(!$orientation)
            $orientation = $this->DefOrientation;
        else
            $orientation = strtoupper($orientation[0]);
        if(!$size)
            $size = $this->DefPageSize;
        else
            $size = $this->_getpagesize($size);
        if($orientation!=$this->CurOrientation || $size[0]!=$this->CurPageSize[0] || $size[1]!=$this->CurPageSize[1])
        {
            if($orientation=='P')
            {
                $this->w = $size[0];
                $this->h = $size[1];
            }
            else
            {
                $this->w = $size[1];
                $this->h = $size[0];
            }
            $this->wPt = $this->w*$this->k;
            $this->hPt = $this->h*$this->k;
            $this->PageBreakTrigger = $this->h-$this->bMargin;
            $this->CurOrientation = $orientation;
            $this->CurPageSize = $size;
        }
        if($orientation!=$this->DefOrientation || $size[0]!=$this->DefPageSize[0] || $size[1]!=$this->DefPageSize[1])
            $this->PageInfo[$this->page]['size'] = array($this->wPt, $this->hPt);
        if($rotation!=0)
        {
            if($rotation%90!=0)
                $this->Error('Incorrect rotation value: '.$rotation);
            $this->CurRotation = $rotation;
            $this->PageInfo[$this->page]['rotation'] = $rotation;
        }
    }

    protected function _endpage()
    {
        $this->state = 1;
    }

    protected function _loadfont($font)
    {
        $a = get_object_vars($this);
        foreach($a as $key=>$val)
            $$key = $val;
        include($this->fontpath.$font);
        $a = get_defined_vars();
        unset($a['this']);
        return $a;
    }

    protected function _isascii($s)
    {
        $nb = strlen($s);
        for($i=0;$i<$nb;$i++)
        {
            if(ord($s[$i])>127)
                return false;
        }
        return true;
    }

    protected function _httpencode($param, $value, $isUTF8)
    {
        if($this->_isascii($value))
            return $param.'="'.$value.'"';
        if(!$isUTF8)
            $value = utf8_encode($value);
        return $param."*=UTF-8''".rawurlencode($value);
    }

    protected function _UTF8encode($s)
    {
        return @iconv('windows-1252','UTF-8',$s);
    }

    protected function _UTF8toUTF16($s)
    {
        $res = "\xFE\xFF";
        $nb = strlen($s);
        $i = 0;
        while($i<$nb)
        {
            $c1 = ord($s[$i++]);
            if($c1>=224)
            {
                $c2 = ord($s[$i++]);
                $c3 = ord($s[$i++]);
                $res .= chr((($c1 & 0x0F)<<4) + (($c2 & 0x3C)>>2));
                $res .= chr((($c2 & 0x03)<<6) + ($c3 & 0x3F));
            }
            elseif($c1>=192)
            {
                $c2 = ord($s[$i++]);
                $res .= chr(($c1 & 0x1C)>>2);
                $res .= chr((($c1 & 0x03)<<6) + ($c2 & 0x3F));
            }
            else
            {
                $res .= "\0".chr($c1);
            }
        }
        return $res;
    }

    protected function _escape($s)
    {
        $s = str_replace('\\','\\\\',$s);
        $s = str_replace('(','\\(',$s);
        $s = str_replace(')','\\)',$s);
        $s = str_replace("\r",'\\r',$s);
        return $s;
    }

    protected function _textstring($s)
    {
        if(!$this->_isascii($s))
            $s = $this->_UTF8toUTF16($s);
        return '('.$this->_escape($s).')';
    }

    protected function _dounderline($x, $y, $txt)
    {
        $up = $this->CurrentFont['up'];
        $ut = $this->CurrentFont['ut'];
        $w = $this->GetStringWidth($txt)+$this->ws*substr_count($txt,' ');
        return sprintf('%.2F %.2F %.2F %.2F re f',$x*$this->k,($this->h-($y-$up/1000*$this->FontSize))*$this->k,$w*$this->k,-$ut/1000*$this->FontSizePt);
    }

    protected function _parsejpg($file)
    {
        $a = getimagesize($file);
        if(!$a)
            $this->Error('Missing or incorrect image file: '.$file);
        if($a[2]!=2)
            $this->Error('Not a JPEG file: '.$file);
        if(!isset($a['channels']) || $a['channels']==3)
            $colspace = 'DeviceRGB';
        elseif($a['channels']==4)
            $colspace = 'DeviceCMYK';
        else
            $colspace = 'DeviceGray';
        $bpc = isset($a['bits']) ? $a['bits'] : 8;
        $data = file_get_contents($file);
        return array('w'=>$a[0], 'h'=>$a[1], 'cs'=>$colspace, 'bpc'=>$bpc, 'f'=>'DCTDecode', 'data'=>$data);
    }

    protected function _parsepng($file)
    {
        $f = fopen($file,'rb');
        if(!$f)
            $this->Error('Can\'t open image file: '.$file);
        $info = $this->_parsepngstream($f,$file);
        fclose($f);
        return $info;
    }

    protected function _parsepngstream($f, $file)
    {
        if(fread($f,8)!=chr(137).'PNG'.chr(13).chr(10).chr(26).chr(10))
            $this->Error('Not a PNG file: '.$file);
        
        fread($f,4);
        if(fread($f,4)!='IHDR')
            $this->Error('Incorrect PNG file: '.$file);
        $w = $this->_readint($f);
        $h = $this->_readint($f);
        $bpc = ord(fread($f,1));
        if($bpc>8)
            $this->Error('16-bit depth not supported: '.$file);
        $ct = ord(fread($f,1));
        if($ct==0 || $ct==4)
            $colspace = 'DeviceGray';
        elseif($ct==2 || $ct==6)
            $colspace = 'DeviceRGB';
        elseif($ct==3)
            $colspace = 'Indexed';
        else
            $this->Error('Unknown color type: '.$file);
        if(ord(fread($f,1))!=0)
            $this->Error('Unknown compression method: '.$file);
        if(ord(fread($f,1))!=0)
            $this->Error('Unknown filter method: '.$file);
        if(ord(fread($f,1))!=0)
            $this->Error('Interlacing not supported: '.$file);
        fread($f,4);
        $dp = '/Predictor 15 /Colors '.($colspace=='DeviceRGB' ? 3 : 1).' /BitsPerComponent '.$bpc.' /Columns '.$w;
        
        $pal = '';
        $trns = '';
        $data = '';
        do
        {
            $n = $this->_readint($f);
            $type = fread($f,4);
            if($type=='PLTE')
            {
                $pal = fread($f,$n);
                fread($f,4);
            }
            elseif($type=='tRNS')
            {
                $t = fread($f,$n);
                if($ct==0)
                    $trns = array(ord(substr($t,1,1)));
                elseif($ct==2)
                    $trns = array(ord(substr($t,1,1)), ord(substr($t,3,1)), ord(substr($t,5,1)));
                else
                {
                    $pos = strpos($t,chr(0));
                    if($pos!==false)
                        $trns = array($pos);
                }
                fread($f,4);
            }
            elseif($type=='IDAT')
            {
                $data .= fread($f,$n);
                fread($f,4);
            }
            elseif($type=='IEND')
                break;
            else
                fread($f,$n+4);
        }
        while($n);
        
        if($colspace=='Indexed' && empty($pal))
            $this->Error('Missing palette in '.$file);
        $info = array('w'=>$w, 'h'=>$h, 'cs'=>$colspace, 'bpc'=>$bpc, 'f'=>'FlateDecode', 'dp'=>$dp, 'pal'=>$pal, 'trns'=>$trns);
        if($ct>=4)
        {
            if(!function_exists('gd_info'))
                $this->Error('GD extension is required for transparency');
            $info['data'] = $this->_toalpha($data, $w, $h);
        }
        else
            $info['data'] = $data;
        return $info;
    }

    protected function _readint($f)
    {
        $a = unpack('Ni',fread($f,4));
        return $a['i'];
    }

    protected function _parsegif($file)
    {
        $this->Error('GIF format is not supported, please convert to PNG');
    }

    protected function _toalpha($data, $w, $h)
    {
        $data = gzuncompress($data);
        $color = '';
        $alpha = '';
        
        $bpl = 4*$w;
        for($i=0;$i<$h;$i++)
        {
            $pos = (1+$bpl)*$i;
            $color .= $data[$pos];
            $alpha .= $data[$pos];
            $line = substr($data,$pos+1,$bpl);
            $color .= preg_replace('/(.).../s','$1',$line);
            $alpha .= preg_replace('/(..).(.)./s','$1$2',$line);
        }
        unset($data);
        $data = gzcompress($color);
        $info = array('w'=>$w, 'h'=>$h, 'cs'=>'DeviceRGB', 'bpc'=>8, 'f'=>'FlateDecode', 'data'=>$data);
        $this->WithAlpha = true;
        return $info;
    }

    protected function _newobj($n=null)
    {
        if($n===null)
            $n = ++$this->n;
        $this->offsets[$n] = strlen($this->buffer);
        $this->_out($n.' 0 obj');
    }

    protected function _putstream($data)
    {
        $this->_out('stream');
        $this->_out($data);
        $this->_out('endstream');
    }

    protected function _out($s)
    {
        if($this->state==2)
            $this->pages[$this->page] .= $s."\n";
        elseif($this->state==1)
            $this->_put($s);
        elseif($this->state==0)
            $this->Error('No page has been added yet');
        elseif($this->state==3)
            $this->Error('The document is closed');
    }

    protected function _put($s)
    {
        $this->buffer .= $s."\n";
    }

    protected function _getoffset()
    {
        return strlen($this->buffer);
    }

    protected function _putpages()
    {
        $nb = $this->page;
        $n = $this->n;
        for($i=1;$i<=$nb;$i++)
        {
            $this->PageInfo[$i]['n'] = ++$n;
        }
        for($i=1;$i<=$nb;$i++)
        {
            $this->_putpage($i);
        }
        
        $this->_newobj(1);
        $this->_put('<</Type /Pages');
        $kids = '/Kids [';
        for($i=1;$i<=$nb;$i++)
            $kids .= $this->PageInfo[$i]['n'].' 0 R ';
        $this->_put($kids.']');
        $this->_put('/Count '.$nb);
        if($this->DefOrientation=='P')
        {
            $w = $this->DefPageSize[0];
            $h = $this->DefPageSize[1];
        }
        else
        {
            $w = $this->DefPageSize[1];
            $h = $this->DefPageSize[0];
        }
        $this->_put(sprintf('/MediaBox [0 0 %.2F %.2F]',$w*$this->k,$h*$this->k));
        $this->_put('>>');
        $this->_put('endobj');
    }

    protected function _putpage($n)
    {
        $this->_newobj($this->PageInfo[$n]['n']);
        $this->_put('<</Type /Page');
        $this->_put('/Parent 1 0 R');
        if(isset($this->PageInfo[$n]['size']))
            $this->_put(sprintf('/MediaBox [0 0 %.2F %.2F]',$this->PageInfo[$n]['size'][0],$this->PageInfo[$n]['size'][1]));
        if(isset($this->PageInfo[$n]['rotation']))
            $this->_put('/Rotate '.$this->PageInfo[$n]['rotation']);
        $this->_put('/Resources 2 0 R');
        if(isset($this->PageLinks[$n]))
        {
            $annots = '/Annots [';
            foreach($this->PageLinks[$n] as $pl)
            {
                $rect = sprintf('%.2F %.2F %.2F %.2F',$pl[0],$pl[1],$pl[0]+$pl[2],$pl[1]-$pl[3]);
                $annots .= '<</Type /Annot /Subtype /Link /Rect ['.$rect.'] /Border [0 0 0] ';
                if(is_string($pl[4]))
                    $annots .= '/A <</S /URI /URI '.$this->_textstring($pl[4]).'>>>>';
                else
                {
                    $l = $this->links[$pl[4]];
                    if(isset($this->PageInfo[$l[0]]['size']))
                        $h = $this->PageInfo[$l[0]]['size'][1];
                    else
                        $h = ($this->DefOrientation=='P') ? $this->DefPageSize[1]*$this->k : $this->DefPageSize[0]*$this->k;
                    $annots .= sprintf('/Dest [%d 0 R /XYZ 0 %.2F null]>>',($this->PageInfo[$l[0]]['n']),$h-$l[1]*$this->k);
                }
            }
            $this->_put($annots.']');
        }
        if($this->WithAlpha)
            $this->_put('/Group <</Type /Group /S /Transparency /CS /DeviceRGB>>');
        $this->_put('/Contents '.($this->n+1).' 0 R>>');
        $this->_put('endobj');
        
        $this->_newobj();
        if($this->compress)
        {
            $p = gzcompress($this->pages[$n]);
            $this->_put('<</Filter /FlateDecode /Length '.strlen($p).'>>');
        }
        else
        {
            $p = $this->pages[$n];
            $this->_put('<</Length '.strlen($p).'>>');
        }
        $this->_putstream($p);
        $this->_put('endobj');
    }

    protected function _putfonts()
    {
        foreach($this->fonts as $k=>$font)
        {
            if(isset($font['type']) && $font['type']=='core')
            {
                $this->fonts[$k]['n'] = $this->n+1;
                $this->_newobj();
                $this->_put('<</Type /Font');
                $this->_put('/BaseFont /'.$font['name']);
                $this->_put('/Subtype /Type1');
                if($font['name']!='Symbol' && $font['name']!='ZapfDingbats')
                    $this->_put('/Encoding /WinAnsiEncoding');
                $this->_put('>>');
                $this->_put('endobj');
            }
        }
    }

    protected function _putimages()
    {
        foreach($this->images as $file=>$info)
        {
            $this->_putimage($info);
            unset($this->images[$file]['data']);
        }
    }

    protected function _putimage(&$info)
    {
        $this->_newobj();
        $info['n'] = $this->n;
        $this->_put('<</Type /XObject');
        $this->_put('/Subtype /Image');
        $this->_put('/Width '.$info['w']);
        $this->_put('/Height '.$info['h']);
        if($info['cs']=='Indexed')
            $this->_put('/ColorSpace [/Indexed /DeviceRGB '.(strlen($info['pal'])/3-1).' '.($this->n+1).' 0 R]');
        else
        {
            $this->_put('/ColorSpace /'.$info['cs']);
            if($info['cs']=='DeviceCMYK')
                $this->_put('/Decode [1 0 1 0 1 0 1 0]');
        }
        $this->_put('/BitsPerComponent '.$info['bpc']);
        if(isset($info['f']))
            $this->_put('/Filter /'.$info['f']);
        if(isset($info['dp']))
            $this->_put('/DecodeParms <<'.$info['dp'].'>>');
        if(isset($info['trns']) && is_array($info['trns']))
        {
            $trns = '';
            for($i=0;$i<count($info['trns']);$i++)
                $trns .= $info['trns'][$i].' '.$info['trns'][$i].' ';
            $this->_put('/Mask ['.$trns.']');
        }
        if(isset($info['smask']))
            $this->_put('/SMask '.($this->n+1).' 0 R');
        $this->_put('/Length '.strlen($info['data']).'>>');
        $this->_putstream($info['data']);
        $this->_put('endobj');
        
        if(isset($info['pal']))
        {
            $this->_newobj();
            $this->_put('<<');
            if($this->compress)
            {
                $pal = gzcompress($info['pal']);
                $this->_put('/Filter /FlateDecode');
            }
            else
                $pal = $info['pal'];
            $this->_put('/Length '.strlen($pal).'>>');
            $this->_putstream($pal);
            $this->_put('endobj');
        }
    }

    protected function _putxobjectdict()
    {
        foreach($this->images as $image)
            $this->_put('/I'.$image['i'].' '.$image['n'].' 0 R');
    }

    protected function _putresourcedict()
    {
        $this->_put('/ProcSet [/PDF /Text /ImageB /ImageC /ImageI]');
        $this->_put('/Font <<');
        foreach($this->fonts as $font)
            $this->_put('/F'.$font['i'].' '.$font['n'].' 0 R');
        $this->_put('>>');
        $this->_put('/XObject <<');
        $this->_putxobjectdict();
        $this->_put('>>');
    }

    protected function _putresources()
    {
        $this->_putfonts();
        $this->_putimages();
        
        $this->offsets[2] = strlen($this->buffer);
        $this->_put('2 0 obj');
        $this->_put('<<');
        $this->_putresourcedict();
        $this->_put('>>');
        $this->_put('endobj');
    }

    protected function _putinfo()
    {
        $this->metadata['Producer'] = 'FPDF '.FPDF_VERSION;
        $this->metadata['CreationDate'] = 'D:'.@date('YmdHis');
        foreach($this->metadata as $key=>$value)
            $this->_put('/'.$key.' '.$this->_textstring($value));
    }

    protected function _putcatalog()
    {
        $n = $this->PageInfo[1]['n'];
        $this->_put('/Type /Catalog');
        $this->_put('/Pages 1 0 R');
        if($this->ZoomMode=='fullpage')
            $this->_put('/OpenAction ['.$n.' 0 R /Fit]');
        elseif($this->ZoomMode=='fullwidth')
            $this->_put('/OpenAction ['.$n.' 0 R /FitH null]');
        elseif($this->ZoomMode=='real')
            $this->_put('/OpenAction ['.$n.' 0 R /XYZ null null 1]');
        elseif(!is_string($this->ZoomMode))
            $this->_put('/OpenAction ['.$n.' 0 R /XYZ null null '.sprintf('%.2F',$this->ZoomMode/100).']');
        if($this->LayoutMode=='single')
            $this->_put('/PageLayout /SinglePage');
        elseif($this->LayoutMode=='continuous')
            $this->_put('/PageLayout /OneColumn');
        elseif($this->LayoutMode=='two')
            $this->_put('/PageLayout /TwoColumnLeft');
    }

    protected function _putheader()
    {
        $this->_put('%PDF-'.$this->PDFVersion);
    }

    protected function _puttrailer()
    {
        $this->_put('/Size '.($this->n+1));
        $this->_put('/Root '.$this->n.' 0 R');
        $this->_put('/Info '.($this->n-1).' 0 R');
    }

    protected function _enddoc()
    {
        $this->state = 1;
        $this->_putheader();
        $this->_putpages();
        $this->_putresources();
        
        $this->_newobj();
        $this->_put('<<');
        $this->_putinfo();
        $this->_put('>>');
        $this->_put('endobj');
        
        $this->_newobj();
        $this->_put('<<');
        $this->_putcatalog();
        $this->_put('>>');
        $this->_put('endobj');
        
        $offset = $this->_getoffset();
        $this->_put('xref');
        $this->_put('0 '.($this->n+1));
        $this->_put('0000000000 65535 f ');
        for($i=1;$i<=$this->n;$i++)
            $this->_put(sprintf('%010d 00000 n ',$this->offsets[$i]));
        
        $this->_put('trailer');
        $this->_put('<<');
        $this->_puttrailer();
        $this->_put('>>');
        $this->_put('startxref');
        $this->_put($offset);
        $this->_put('%%EOF');
        $this->state = 3;
    }
}

// Definir fuentes básicas
if(!function_exists('_set_core_fonts')) {
    function _set_core_fonts() {
        $cw = array();
        for($i=0;$i<=255;$i++)
            $cw[chr($i)] = 600;
        return $cw;
    }
}

} // end if(!class_exists('FPDF'))
?>
