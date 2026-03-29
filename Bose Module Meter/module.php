<?php

class BoseModuleMeter extends IPSModule
{
    // ── Lifecycle ──────────────────────────────────────────────

    public function Create()
    {
        parent::Create();

        $this->ConnectParent("{69EBE0DC-8DDF-6F4E-E21A-5AC40FAF2050}");

        // Meter channel list: [{Position, NodeName, Label, Index}]
        $this->RegisterPropertyString('MeterChannels', '[]');

        // Display settings
        $this->RegisterPropertyInteger('YellowThreshold', -24);
        $this->RegisterPropertyInteger('RedThreshold', -10);
        $this->RegisterPropertyInteger('MeterHeight', 180);
        $this->RegisterPropertyInteger('UpdateInterval', 500);

        // Timer for periodic HTML rewrite
        $this->RegisterTimer('MeterUpdate', 0, 'BOSE_UpdateMeterHTML(' . $this->InstanceID . ');');

        // Buffer for current meter levels, keyed by "NodeName>Index"
        $this->SetBuffer('MeterLevels', '{}');
        $this->SetBuffer('PeakLevels', '{}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $channels = $this->GetMeterChannels();

        if (count($channels) === 0) {
            $this->SetStatus(200);
            $this->SetTimerInterval('MeterUpdate', 0);
            return;
        }

        $this->MaintainVariable('LevelMeter', 'Level Meter', VARIABLETYPE_STRING, '~HTMLBox', 0, true);

        if ($this->HasActiveParent()) {
            $this->SubscribeMeters($channels);
        }

        $interval = (int)$this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('MeterUpdate', max(200, $interval));

        $this->RenderAndSetHTML();

        $this->SetStatus(102);
    }

    // ── Data from Splitter ────────────────────────────────────

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!isset($data['Buffer']) || !is_array($data['Buffer'])) {
            return;
        }

        $buffer = $data['Buffer'];
        $moduleName = (string)$buffer['moduleName'];
        $index1 = (int)$buffer['Index1'];
        $value = (float)$buffer['Value'];

        // Check if this message matches any of our configured channels
        $channels = $this->GetMeterChannels();
        foreach ($channels as $ch) {
            if ((string)$ch['NodeName'] === $moduleName && (int)$ch['Index'] === $index1) {
                $this->SendDebug('Meter.Receive', $moduleName . '>' . $index1 . '=' . $value, 0);
                $this->UpdateMeterLevel($moduleName, $index1, $value);
                return;
            }
        }
    }

    // ── Public functions ──────────────────────────────────────

    public function UpdateMeterHTML()
    {
        $this->RenderAndSetHTML();
    }

    // ── Private: Protocol ─────────────────────────────────────

    private function SubscribeMeters(array $channels)
    {
        foreach ($channels as $ch) {
            $nodeName = (string)$ch['NodeName'];
            $index = (int)$ch['Index'];
            $cmd = 'SUB "GA"' . $nodeName . '">' . $index . '"';
            $this->SendCommand($cmd);
            $this->SendDebug('Meter.Subscribe', $cmd, 0);
        }
    }

    private function UnsubscribeMeters(array $channels)
    {
        foreach ($channels as $ch) {
            $nodeName = (string)$ch['NodeName'];
            $index = (int)$ch['Index'];
            $cmd = 'UNS "GA"' . $nodeName . '">' . $index . '"';
            $this->SendCommand($cmd);
        }
    }

    private function SendCommand($msg)
    {
        $this->SendDataToParent(json_encode([
            'DataID' => '{E13A162B-3414-BD54-5C48-F802F8323D2B}',
            'Buffer' => $msg . "\r"
        ]));
    }

    // ── Private: State Management ─────────────────────────────

    private function UpdateMeterLevel($nodeName, $index, $db)
    {
        $key = $nodeName . '>' . $index;

        $levels = json_decode($this->GetBuffer('MeterLevels'), true);
        if (!is_array($levels)) $levels = [];
        $levels[$key] = round($db, 1);
        $this->SetBuffer('MeterLevels', json_encode($levels));

        $peaks = json_decode($this->GetBuffer('PeakLevels'), true);
        if (!is_array($peaks)) $peaks = [];
        $currentPeak = isset($peaks[$key]) ? (float)$peaks[$key] : -80.0;
        if ($db > $currentPeak) {
            $peaks[$key] = round($db, 1);
        } else {
            $peaks[$key] = round($currentPeak - 0.3, 1);
        }
        $this->SetBuffer('PeakLevels', json_encode($peaks));
    }

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

    private function RenderAndSetHTML()
    {
        $channels = $this->GetMeterChannels();
        $levels = json_decode($this->GetBuffer('MeterLevels'), true);
        $peaks = json_decode($this->GetBuffer('PeakLevels'), true);
        if (!is_array($levels)) $levels = [];
        if (!is_array($peaks)) $peaks = [];

        $yellowDb = (int)$this->ReadPropertyInteger('YellowThreshold');
        $redDb = (int)$this->ReadPropertyInteger('RedThreshold');
        $height = (int)$this->ReadPropertyInteger('MeterHeight');

        $html = $this->BuildMeterHTML($channels, $levels, $peaks, $yellowDb, $redDb, $height);
        $this->SetValueIfChanged('LevelMeter', $html);
    }

    private function BuildMeterHTML(array $channels, array $levels, array $peaks, $yellowDb, $redDb, $height)
    {
        $meterData = [];
        foreach ($channels as $ch) {
            $nodeName = (string)$ch['NodeName'];
            $index = (int)$ch['Index'];
            $key = $nodeName . '>' . $index;
            $meterData[] = [
                'label' => (string)$ch['Label'],
                'db'    => isset($levels[$key]) ? (float)$levels[$key] : -80.0,
                'peak'  => isset($peaks[$key]) ? (float)$peaks[$key] : -80.0,
            ];
        }

        $jsonData = json_encode($meterData);

        $html = '<div id="bose-meter-root" style="font-family:-apple-system,\'Segoe UI\',Roboto,sans-serif;background:#1a1a2e;padding:12px;border-radius:8px;">'
            . '<canvas id="bose-meter-canvas" width="0" height="0"></canvas>'
            . '<script>(function(){'
            . 'var DATA=' . $jsonData . ';'
            . 'var YELLOW_DB=' . $yellowDb . ';'
            . 'var RED_DB=' . $redDb . ';'
            . 'var H=' . $height . ';'
            . 'var DB_MIN=-80,DB_MAX=0;'
            . 'var W=24,GAP=14,PAD_LEFT=36,PAD_TOP=16,PAD_BOTTOM=40;'
            . 'var COL_GREEN=\'#00c853\',COL_YELLOW=\'#ffd600\',COL_RED=\'#ff1744\';'
            . 'var COL_BG=\'#0a0a1e\',COL_BORDER=\'#1e1e3a\',COL_TEXT=\'#9999bb\',COL_DB=\'#ccccee\';'
            . 'var canvas=document.getElementById(\'bose-meter-canvas\');'
            . 'var totalW=PAD_LEFT+DATA.length*(W+GAP)+10;'
            . 'var totalH=PAD_TOP+H+PAD_BOTTOM;'
            . 'canvas.width=totalW;canvas.height=totalH;'
            . 'canvas.style.width=totalW+\'px\';canvas.style.height=totalH+\'px\';'
            . 'var ctx=canvas.getContext(\'2d\');'
            . 'function dbToY(db){var c=Math.max(DB_MIN,Math.min(DB_MAX,db));return((c-DB_MIN)/(DB_MAX-DB_MIN))*H;}'
            . 'function drawSegments(x,fillH,w){'
            . 'if(fillH<=0)return;'
            . 'var yellowY=dbToY(YELLOW_DB);var redY=dbToY(RED_DB);var base=PAD_TOP+H;'
            . 'var gH=Math.min(fillH,yellowY);'
            . 'if(gH>0){ctx.fillStyle=COL_GREEN;ctx.fillRect(x,base-gH,w,gH);}'
            . 'if(fillH>yellowY){var yH=Math.min(fillH,redY)-yellowY;if(yH>0){ctx.fillStyle=COL_YELLOW;ctx.fillRect(x,base-Math.min(fillH,redY),w,yH);}}'
            . 'if(fillH>redY){var rH=fillH-redY;ctx.fillStyle=COL_RED;ctx.fillRect(x,base-fillH,w,rH);}'
            . 'ctx.fillStyle=COL_BG;for(var sy=0;sy<H;sy+=5){ctx.fillRect(x,PAD_TOP+sy,w,1);}}'
            . 'function draw(){'
            . 'ctx.clearRect(0,0,canvas.width,canvas.height);'
            . 'ctx.font=\'9px monospace\';ctx.textAlign=\'right\';'
            . 'var scaleVals=[0,RED_DB,YELLOW_DB,-40,-60];'
            . 'for(var s=0;s<scaleVals.length;s++){'
            . 'var sy=PAD_TOP+H-dbToY(scaleVals[s]);'
            . 'ctx.fillStyle=scaleVals[s]>=RED_DB?\'#ff174466\':(scaleVals[s]>=YELLOW_DB?\'#ffd60066\':\'#44445e\');'
            . 'ctx.fillText(scaleVals[s].toString(),PAD_LEFT-8,sy+3);}'
            . 'for(var i=0;i<DATA.length;i++){'
            . 'var m=DATA[i];var x=PAD_LEFT+i*(W+GAP);var base=PAD_TOP+H;'
            . 'ctx.fillStyle=COL_BG;ctx.strokeStyle=COL_BORDER;ctx.lineWidth=1;'
            . 'ctx.fillRect(x,PAD_TOP,W,H);ctx.strokeRect(x-0.5,PAD_TOP-0.5,W+1,H+1);'
            . 'drawSegments(x,dbToY(m.db),W);'
            . 'var peakY=dbToY(m.peak);'
            . 'if(peakY>0){var peakColor=m.peak>=RED_DB?COL_RED:(m.peak>=YELLOW_DB?COL_YELLOW:COL_GREEN);'
            . 'ctx.fillStyle=peakColor;ctx.fillRect(x,base-peakY-1,W,2);}'
            . 'var dotX=x+W/2;var dotY=PAD_TOP-8;'
            . 'ctx.beginPath();ctx.arc(dotX,dotY,4,0,Math.PI*2);'
            . 'ctx.fillStyle=m.db>-1?COL_RED:\'#2a2a3a\';ctx.fill();'
            . 'if(m.db>-1){ctx.shadowColor=COL_RED;ctx.shadowBlur=6;ctx.fill();ctx.shadowBlur=0;}'
            . 'ctx.fillStyle=COL_DB;ctx.font=\'10px monospace\';ctx.textAlign=\'center\';'
            . 'var dbText=m.db<=-79?\'\\u2013\\u221e\':m.db.toFixed(1);'
            . 'ctx.fillText(dbText,x+W/2,base+14);'
            . 'ctx.fillStyle=COL_TEXT;ctx.font=\'10px sans-serif\';'
            . 'ctx.fillText(m.label,x+W/2,base+28);}}'
            . 'draw();'
            . '})();</script></div>';

        return $html;
    }

    // ── Private: Helpers ──────────────────────────────────────

    private function SetValueIfChanged($ident, $value)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === false || $vid === 0) return;
        if (GetValue($vid) === $value) return;
        $this->SetValue($ident, $value);
    }
}
