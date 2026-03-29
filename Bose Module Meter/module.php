<?php

class BoseModuleMeter extends IPSModule
{
    // ── Lifecycle ──────────────────────────────────────────────

    public function Create()
    {
        parent::Create();

        $this->ConnectParent("{69EBE0DC-8DDF-6F4E-E21A-5AC40FAF2050}");

        if (!IPS_VariableProfileExists('BoseSignalStatus')) {
            IPS_CreateVariableProfile('BoseSignalStatus', 0);
        }
        IPS_SetVariableProfileAssociation('BoseSignalStatus', false, 'Kein Signal', 'Warning', 0xff4444);
        IPS_SetVariableProfileAssociation('BoseSignalStatus', true,  'Signal',      'Ok',      0x00cc44);

        $this->RegisterPropertyString('MeterChannels', '[]');
        $this->RegisterPropertyInteger('YellowThreshold', -24);
        $this->RegisterPropertyInteger('RedThreshold', -10);
        $this->RegisterPropertyInteger('MeterHeight', 180);
        $this->RegisterPropertyInteger('BackgroundColor', 0x1a1a2e);
        $this->RegisterPropertyInteger('BackgroundOpacity', 100);
        $this->RegisterPropertyInteger('BarWidth', 32);

        $this->RegisterTimer('GlPoll',     0, 'BOSE_PollGl(' . $this->InstanceID . ');');
        $this->RegisterTimer('MeterUpdate', 0, 'BOSE_UpdateMeterHTML(' . $this->InstanceID . ');');

        $this->SetBuffer('SampleBuffer', '{}');
        $this->SetBuffer('LastAverage',  '{}');
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
            $pos   = (int)$ch['Position'];
            $label = (string)$ch['Label'] !== '' ? (string)$ch['Label'] : 'Slot ' . $ch['Slot'] . ' Ch ' . $ch['Channel'];
            $this->MaintainVariable('Level_'  . $pos, $label,             VARIABLETYPE_FLOAT,   'BoseGainLevelDB',  $pos * 2 + 1, true);
            $this->MaintainVariable('Signal_' . $pos, $label . ' Signal', VARIABLETYPE_BOOLEAN, 'BoseSignalStatus', $pos * 2 + 2, true);
            $lvlID = $this->GetIDForIdent('Level_' . $pos);
            if ($lvlID > 0) {
                $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
                if (count($archiveIDs) > 0) {
                    AC_SetLoggingStatus($archiveIDs[0], $lvlID, false);
                }
            }
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

        $samples = json_decode($this->GetBuffer('SampleBuffer'), true);
        $peaks   = json_decode($this->GetBuffer('PeakLevels'), true);
        if (!is_array($samples)) $samples = [];
        if (!is_array($peaks))   $peaks   = [];

        foreach ($this->GetMeterChannels() as $ch) {
            if ((int)$ch['Slot'] !== $slot) continue;
            $chNum = (int)$ch['Channel'];
            $idx   = $chNum - 1;
            if (!isset($levels[$idx])) continue;

            $db  = (float)$levels[$idx];
            $key = $slot . ':' . $chNum;

            if (!isset($samples[$key])) $samples[$key] = [];
            $samples[$key][] = $db;
            if (count($samples[$key]) > 30) {
                $samples[$key] = array_slice($samples[$key], -30);
            }

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

            $pos       = (int)$ch['Position'];
            $threshold = isset($ch['Threshold']) ? (int)$ch['Threshold'] : -50;
            $this->SetValueIfChanged('Level_'  . $pos, $averages[$key]);
            $this->SetValueIfChanged('Signal_' . $pos, $averages[$key] > $threshold);
        }

        $this->SetBuffer('SampleBuffer', '{}');
        $this->SetBuffer('LastAverage', json_encode($averages));

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

        $jsonData  = json_encode($meterData);
        $cacheKey  = 'bm_' . $this->InstanceID;

        $html = '<div id="bose-meter-root" style="font-family:-apple-system,\'Segoe UI\',Roboto,sans-serif;background:' . htmlspecialchars($bgColor, ENT_QUOTES) . ';padding:12px 12px 8px;margin:0;box-sizing:border-box;width:100%;">'
            . '<canvas id="bose-meter-canvas" style="display:block;"></canvas>'
            . '<script>(function(){'
            . 'var DATA=' . $jsonData . ';'
            . 'var YELLOW_DB=' . $yellowDb . ';'
            . 'var RED_DB=' . $redDb . ';'
            . 'var H=' . $height . ';'
            . 'var DURATION=950;'
            . 'var DB_MIN=-60,DB_MAX=0;'
            . 'var GAP=10,PAD_LEFT=36,PAD_TOP=16,PAD_BOTTOM=40;'
            . 'var W_MIN=8,W_MAX=' . $barWidth . ';'
            . 'var COL_GREEN=\'#00c853\',COL_YELLOW=\'#ffd600\',COL_RED=\'#ff1744\';'
            . 'var COL_BG=\'#0a0a1e\',COL_BORDER=\'#1e1e3a\',COL_TEXT=\'#9999bb\',COL_DB=\'#ccccee\',COL_ROOT=\'' . addslashes($bgColor) . '\';'
            . 'var CACHE_KEY=\'' . $cacheKey . '\';'
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
            . 'function drawScale(){'
            . 'ctx.font=\'9px monospace\';ctx.textAlign=\'right\';'
            . 'var scaleVals=[0,RED_DB,YELLOW_DB,-40,-60];'
            . 'for(var i=0;i<scaleVals.length;i++){'
            . 'var sy=PAD_TOP+H-dbToY(scaleVals[i]);'
            . 'ctx.fillStyle=\'#44445e\';'
            . 'ctx.fillText(scaleVals[i].toString(),PAD_LEFT-8,sy+3);}}'
            . 'function drawMeter(dv,m,x){'
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
            . 'ctx.save();ctx.beginPath();ctx.rect(x-GAP/2,base,W+GAP,PAD_BOTTOM);ctx.clip();'
            . 'ctx.fillStyle=COL_DB;ctx.font=\'10px monospace\';ctx.textAlign=\'center\';'
            . 'ctx.fillText(m.db<=-59?\'\\u2013\\u221e\':m.db.toFixed(1),x+W/2,base+14);'
            . 'ctx.fillStyle=COL_TEXT;ctx.font=\'10px sans-serif\';'
            . 'ctx.fillText(m.label,x+W/2,base+28);'
            . 'ctx.restore();}'
            // Draw a full frame at a fixed interpolation value s (0=prev, 1=target)
            . 'function drawFrame(s){'
            . 'ctx.fillStyle=COL_ROOT;ctx.fillRect(0,0,totalW,totalH);'
            . 'drawScale();'
            . 'for(var i=0;i<DATA.length;i++){'
            . 'var m=DATA[i];var x=PAD_LEFT+i*(W+GAP);'
            . 'var dv=m.prev+(m.db-m.prev)*s;'
            . 'drawMeter(dv,m,x);}}'
            // Draw PREV state synchronously before first paint → minimises blank flash
            . 'drawFrame(0);'
            // Try to use sessionStorage for even better PREV (last rendered state)
            . 'try{'
            . 'var cached=JSON.parse(sessionStorage.getItem(CACHE_KEY)||\'null\');'
            . 'if(cached&&cached.length===DATA.length){'
            . 'for(var ci=0;ci<DATA.length;ci++){DATA[ci].prev=cached[ci].db;}'
            . 'drawFrame(0);}'
            . '}catch(e){}'
            // Animated rAF loop
            . 'function frame(ts){'
            . 'if(!frame.t0)frame.t0=ts;'
            . 'var e=Math.min(1,(ts-frame.t0)/DURATION);'
            . 'var s=e<1?e*(2-e):1;'
            . 'drawFrame(s);'
            . 'if(e<1){requestAnimationFrame(frame);}'
            . 'else{'
            // Save final state to sessionStorage for next reload
            . 'try{sessionStorage.setItem(CACHE_KEY,JSON.stringify(DATA.map(function(m){return{db:m.db,peak:m.peak};})));}catch(e){}'
            . '}}'
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
