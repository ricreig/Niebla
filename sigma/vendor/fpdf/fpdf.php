<?php
/*
Minimal FPDF embed (https://www.fpdf.org/), version stripped for this project use.
*/
class FPDF{
  protected $buffer=''; function AddPage(){}
  function SetFont($fam,$style='',$size=12){}
  function Cell($w,$h,$txt){ $this->buffer.=$txt."\n"; }
  function Output(){ header('Content-Type: text/plain; charset=utf-8'); echo $this->buffer; }
}
