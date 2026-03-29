<?php

class BoseModuleMeter extends IPSModule
{
    // ── Lifecycle ──────────────────────────────────────────────

    public function Create()
    {
        parent::Create();

        $this->ConnectParent("{69EBE0DC-8DDF-6F4E-E21A-5AC40FAF2050}");

        $this->RegisterPropertyString('MeterChannels', '[]');
        $this->RegisterPropertyInteger('YellowThreshold', -24);
        $this->RegisterPropertyInteger('RedThreshold', -10);
        $this->RegisterPropertyInteger('MeterHeight', 180);
        $this->RegisterPropertyInteger('BackgroundColor', 0x1a1a2e);
        $this->RegisterPropertyInteger('BackgroundOpacity', 100);
        $this->RegisterPropertyInteger('BarWidth', 32);

        // Poll GL every 100 ms, accumulate samples
        $this->RegisterTimer('GlPoll', 0, 'BOSE_PollGl(' . $this->InstanceID . ');');
        // Every 1000 ms: compute average, write HTML
        $this->RegisterTimer('MeterUpdate', 0, 'BOSE_UpdateMeterHTML(' . $this->InstanceID . ');');

        $this->SetBuffer('SampleBuffer', '{}'); // {"key": [v1,v2,...]}
        $this->SetBuffer('LastAverage',  '{}'); // {"key": avg}
        $this->SetBuffer('PeakLevels',   '{}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $channels = $this->GetMeterChannels();

        if (count($channels) === 0) {
            $this->SetStatus(200);
            $this->SetTimerInterval('GlPoll', 0);
            $this->SetTimerInterval('MeterUpdate', 0);
            return;
        }

        $this->MaintainVariable('LevelMeter', 'Level Meter', VARIABLETYPE_STRING, '~HTMLBox', 0, true);

        foreach ($channels as $ch) {
            $ident = 'Level_' . (int)$ch['Position'];
            $label = (string)$ch['Label'] !== '' ? (string)$ch['Label'] : 'Slot ' . $ch['Slot'] . ' Ch ' . $ch['Channel'];
            $this->MaintainVariable($ident, $label, VARIABLETYPE_FLOAT, 'BoseGainLevelDB', (int)$ch['Position'] + 1, true);
        }

        $this->SetTimerInterval('GlPoll', 100);
        $this->SetTimerInterval('MeterUpdate', 1000);
        $this->SetStatus(102);
    }

    // ── Data from Splitter ────────────────────────────────────

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!isset($data['Buffer']) || !is_array($data['Buffer'])) return;
        $buffer = $data['Buffer'];
        if (!isset($buffer['command']) || $buffer['command'] !== 'GL') return;

        $slot   = (int)$buffer['slot'];
        $levels = $buffer['levels'];

        $samples  = json_decode($this->GetBuffer('SampleBuffer'), true);
        $peaks    = json_decode($this->GetBuffer('PeakLevels'), true);
        if (!is_array($samples)) $samples = [];
        if (!is_array($peaks))   $peaks   = [];

        foreach ($this->GetMeterChannels() as $ch) {
            if ((int)$ch['Slot'] !== $slot) continue;
            $chNum = (int)$ch['Channel'];
            $idx   = $chNum - 1;
            if (!isset($levels[$idx])) continue;

            $db  = (float)$levels[$idx];
            $key = $slot . ':' . $chNum;

            // Accumulate sample
            if (!isset($samples[$key])) $samples[$key] = [];
            $samples[$key][] = $db;
            if (count($samples[$key]) > 30) {
                $samples[$key] = array_slice($samples[$key], -30);
            }

            // Peak: instant attack, slow decay
            $peak = isset($peaks[$key]) ? (float)$peaks[$key] : $db;
            $peaks[$key] = round($db > $peak ? $db : $peak - 0.1, 1);
        }

        $this->SetBuffer('SampleBuffer', json_encode($samples));
        $this->SetBuffer('PeakLevels',   json_encode($peaks));
    }

    // ── Public functions ──────────────────────────────────────

    public function PollGl()
    {
        $slots = [];
        foreach ($this->GetMeterChannels() as $ch) {
            $slots[(int)$ch['Slot']] = true;
        }
        foreach (array_keys($slots) as $slot) {
            $this->SendCommand('GL ' . $slot);
        }
    }

    public function UpdateMeterHTML()
    {
        $channels = $this->GetMeterChannels();
        if (count($channels) === 0) return;

        // Compute 1-second averages from sample buffer
        $samples = json_decode($this->GetBuffer('SampleBuffer'), true);
        $peaks   = json_decode($this->GetBuffer('PeakLevels'), true);
        $lastAvg = json_decode($this->GetBuffer('LastAverage'), true);
        if (!is_array($samples)) $samples = [];
        if (!is_array($peaks))   $peaks   = [];
        if (!is_array($lastAvg)) $lastAvg = [];

        $averages = [];
        foreach ($channels as $ch) {
            $key = (int)$ch['Slot'] . ':' . (int)$ch['Channel'];
            if (isset($samples[$key]) && count($samples[$key]) > 0) {
                $averages[$key] = round(array_sum($samples[$key]) / count($samples[$key]), 1);
            } else {
                $averages[$key] = isset($lastAvg[$key]) ? (float)$lastAvg[$key] : -60.0;
            }
            // Write float variable
            $ident = 'Level_' . (int)$ch['Position'];
            $this->SetValueIfChanged($ident, $averages[$key]);
        }

        // Reset sample buffer for next second
        $this->SetBuffer('SampleBuffer', '{}');
        $this->SetBuffer('LastAverage', json_encode($averages));

        // Render HTML: prev = lastAvg, target = averages
        $html = $this->BuildMeterHTML($channels, $lastAvg, $averages, $peaks);
        $this->SetValueIfChanged('LevelMeter', $html);
    }

    // ── Private: Protocol ─────────────────────────────────────

    private function SendCommand(string $msg)
    {
        $this->SendDataToParent(json_encode([
            'DataID' => '{E13A162B-3414-BD54-5C48-F802F8323D2B}',
            'Buffer' => $msg . "\r"
        ]));
    }

    // ── Private: Channels ─────────────────────────────────────

    private function GetMeterChannels()
    {
        $channels = json_decode($this->ReadPropertyString('MeterChannels'), true);
        if (!is_array($channels)) return [];
        usort($channels, function ($a, $b) {
            return (int)$a['Position'] - (int)$b['Position'];
        });
        return $channels;
    }

    // ── Private: HTML Rendering ───────────────────────────────

    private function BuildMeterHTML(array $channels, array $prev, array $target, array $peaks)
    {
        $yellowDb = (int)$this->ReadPropertyInteger('YellowThreshold');
        $redDb    = (int)$this->ReadPropertyInteger('RedThreshold');
        $height   = (int)$this->ReadPropertyInteger('MeterHeight');
        $bgInt    = (int)$this->ReadPropertyInteger('BackgroundColor') & 0xFFFFFF;
        $opacity  = max(0, min(100, (int)$this->ReadPropertyInteger('BackgroundOpacity'))) / 100.0;
        $bgColor  = sprintf('rgba(%d,%d,%d,%.2f)', ($bgInt >> 16) & 0xFF, ($bgInt >> 8) & 0xFF, $bgInt & 0xFF, $opacity);
        $barWidth = max(8, min(80, (int)$this->ReadPropertyInteger('BarWidth')));

        $meterData = [];
        foreach ($channels as $ch) {
            $key   = (int)$ch['Slot'] . ':' . (int)$ch['Channel'];
            $label = (string)$ch['Label'] !== '' ? (string)$ch['Label'] : 'S' . $ch['Slot'] . 'C' . $ch['Channel'];
            $t     = isset($target[$key]) ? (float)$target[$key] : -60.0;
            $p     = isset($prev[$key])   ? (float)$prev[$key]   : $t;
            $meterData[] = [
                'label' => $label,
                'prev'  => $p,
                'db'    => $t,
                'peak'  => isset($peaks[$key]) ? (float)$peaks[$key] : -60.0,
            ];
        }

        $jsonData = json_encode($meterData);

        $html = '<div id="bose-meter-root" style="font-family:-apple-system,\'Segoe UI\',Roboto,sans-serif;background:' . htmlspecialchars($bgColor, ENT_QUOTES) . ';padding:12px 12px 8px;margin:0;box-sizing:border-box;width:100%;">'
            . '<canvas id="bose-meter-canvas" style="display:block;"></canvas>'
            . '<script>(function(){'
            . 'var DATA=' . $jsonData . ';'
            . 'var YELLOW_DB=' . $yellowDb . ';'
            . 'var RED_DB=' . $redDb . ';'
            . 'var H=' . $height . ';'
            . 'var DURATION=950;'
            . 'var DB_MIN=-60,DB_MAX=0;'
            . 'var GAP=10,PAD_LEFT=36,PAD_TOP=16,PAD_BOTTOM=48;'
            . 'var W_MIN=8,W_MAX=' . $barWidth . ';'
            . 'var COL_GREEN=\'#00c853\',COL_YELLOW=\'#ffd600\',COL_RED=\'#ff1744\';'
            . 'var COL_BG=\'#0a0a1e\',COL_BORDER=\'#1e1e3a\',COL_TEXT=\'#9999bb\',COL_DB=\'#ccccee\',COL_ROOT=\'' . addslashes($bgColor) . '\';'
            . 'var DPR=window.devicePixelRatio||1;'
            . 'var canvas=document.getElementById(\'bose-meter-canvas\');'
            . 'var root=document.getElementById(\'bose-meter-root\');'
            . 'var availW=Math.max(200,(root.offsetWidth||window.innerWidth||300))-24;'
            . 'var n=Math.max(1,DATA.length);'
            . 'var W=Math.min(W_MAX,Math.max(W_MIN,Math.floor((availW-PAD_LEFT-GAP*(n-1))/n)));'
            . 'var totalW=PAD_LEFT+n*(W+GAP)-GAP+10;'
            . 'var totalH=PAD_TOP+H+PAD_BOTTOM;'
            . 'canvas.width=totalW*DPR;canvas.height=totalH*DPR;'
            . 'canvas.style.width=totalW+\'px\';canvas.style.height=totalH+\'px\';'
            . 'var ctx=canvas.getContext(\'2d\');ctx.scale(DPR,DPR);'
            . 'function dbToY(db){var c=Math.max(DB_MIN,Math.min(DB_MAX,db));return((c-DB_MIN)/(DB_MAX-DB_MIN))*H;}'
            . 'function drawBar(x,fillH,w){'
            . 'if(fillH<=0)return;'
            . 'var yellowY=dbToY(YELLOW_DB);var redY=dbToY(RED_DB);var base=PAD_TOP+H;'
            . 'var gH=Math.min(fillH,yellowY);'
            . 'if(gH>0){ctx.fillStyle=COL_GREEN;ctx.fillRect(x,base-gH,w,gH);}'
            . 'if(fillH>yellowY){var yH=Math.min(fillH,redY)-yellowY;if(yH>0){ctx.fillStyle=COL_YELLOW;ctx.fillRect(x,base-Math.min(fillH,redY),w,yH);}}'
            . 'if(fillH>redY){var rH=fillH-redY;ctx.fillStyle=COL_RED;ctx.fillRect(x,base-fillH,w,rH);}}'
            . 'function draw(dv,m,x){'
            . 'var base=PAD_TOP+H;'
            . 'ctx.fillStyle=COL_BG;ctx.strokeStyle=COL_BORDER;ctx.lineWidth=1;'
            . 'ctx.fillRect(x,PAD_TOP,W,H);ctx.strokeRect(x-0.5,PAD_TOP-0.5,W+1,H+1);'
            . 'drawBar(x,dbToY(dv),W);'
            . 'var peakY=dbToY(m.peak);'
            . 'if(peakY>0){var pc=m.peak>=RED_DB?COL_RED:(m.peak>=YELLOW_DB?COL_YELLOW:COL_GREEN);'
            . 'ctx.fillStyle=pc;ctx.fillRect(x,base-peakY-1,W,2);}'
            . 'ctx.beginPath();ctx.arc(x+W/2,PAD_TOP-8,4,0,Math.PI*2);'
            . 'ctx.fillStyle=dv>-1?COL_RED:\'#2a2a3a\';ctx.fill();'
            . 'if(dv>-1){ctx.shadowColor=COL_RED;ctx.shadowBlur=6;ctx.fill();ctx.shadowBlur=0;}'
            . 'ctx.save();ctx.translate(x+W/2,base+6);ctx.rotate(-Math.PI/2);'
            . 'ctx.fillStyle=COL_DB;ctx.font=\'9px monospace\';ctx.textAlign=\'left\';'
            . 'ctx.fillText(dv<=-59?\'\\u2013\\u221e\':dv.toFixed(1),0,0);'
            . 'ctx.restore();'
            . 'ctx.fillStyle=COL_TEXT;ctx.font=\'9px sans-serif\';ctx.textAlign=\'center\';'
            . 'ctx.fillText(m.label,x+W/2,base+40);}'
            . 'function frame(ts){'
            . 'if(!frame.t0)frame.t0=ts;'
            . 'var e=Math.min(1,(ts-frame.t0)/DURATION);'
            . 'var s=e<1?e*(2-e):1;' // ease-out quad
            . 'ctx.fillStyle=COL_ROOT;ctx.fillRect(0,0,totalW,totalH);'
            . 'ctx.font=\'9px monospace\';ctx.textAlign=\'right\';'
            . 'var scaleVals=[0,RED_DB,YELLOW_DB,-40,-60];'
            . 'for(var s2=0;s2<scaleVals.length;s2++){'
            . 'var sy=PAD_TOP+H-dbToY(scaleVals[s2]);'
            . 'ctx.fillStyle=scaleVals[s2]>=RED_DB?\'#ff174466\':(scaleVals[s2]>=YELLOW_DB?\'#ffd60066\':\'#44445e\');'
            . 'ctx.fillText(scaleVals[s2].toString(),PAD_LEFT-8,sy+3);}'
            . 'for(var i=0;i<DATA.length;i++){'
            . 'var m=DATA[i];var x=PAD_LEFT+i*(W+GAP);'
            . 'var dv=m.prev+(m.db-m.prev)*s;'
            . 'draw(dv,m,x);}'
            . 'if(e<1)requestAnimationFrame(frame);}'
            . 'requestAnimationFrame(frame);'
            . '})();</script></div>';

        return $html;
    }

    // ── Private: Helpers ──────────────────────────────────────

    private function SetValueIfChanged(string $ident, $value)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === false || $vid === 0) return;
        if (GetValue($vid) === $value) return;
        $this->SetValue($ident, $value);
    }
}
