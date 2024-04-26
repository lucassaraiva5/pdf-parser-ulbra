<?php

namespace App\Controller;

use App\Entity\File;
use App\Form\FileInputType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\String\Slugger\SluggerInterface;

class DefaultController extends AbstractController
{
    public $array = [];
    public $arrayDisciplinas = [];


    #[Route('/', name: 'app_index')]
    public function index(Request $request, SluggerInterface $slugger): Response
    {
        $fileEntity = new File();
        $form = $this->createForm(FileInputType::class, $fileEntity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $brochureFile */
            $file = $form->get('file')->getData();

            // this condition is needed because the 'brochure' field is not required
            // so the PDF file must be processed only when a file is uploaded
            if ($file) {
                $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                // this is needed to safely include the file name as part of the URL
                $safeFilename = $slugger->slug($originalFilename);
                $filenameRaw = $safeFilename.'-'.uniqid();
                $newFilename = $filenameRaw.'.'.$file->guessExtension();

                // Move the file to the directory where brochures are stored
                try {
                    $file->move(
                        $this->getParameter('files_directory'),
                        $newFilename
                    );
                } catch (FileException $e) {
                    // ... handle exception if something happens during file upload
                }

                // updates the 'brochureFilename' property to store the PDF file name
                // instead of its contents
                $fileEntity->setName($newFilename);
            }

            return $this->redirectToRoute('app_default', ['filename' => $filenameRaw]);
        }

        return $this->render('upload/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/upload/{filename}', name: 'app_default')]
    public function generate(Request $request, $filename): Response
    {

        $filePath = $this->getParameter('files_directory') . DIRECTORY_SEPARATOR . $filename . ".pdf";

        ini_set('memory_limit', -1);
        ini_set('max_execution_time', -1);

        $parser = new \Smalot\PdfParser\Parser();

        $pdf = $parser->parseFile($filePath);

        $text = $pdf->getText();

        $array = explode("Universidade Luterana do Brasil - Campus Torres", $text);

        unset($array[0]);
        $indexAluno = -1;
        foreach($array as $pagina) {
            $pagina =str_replace([
                "Campus Torres",
                "- Campus",
                "R. Universitária",
                "1900",
                " Parque do Balonismo ",
                " CEP: 95560-000","Torres",
                "- RS -"," Brasil",
                " - (51) 3626 2000",
                " - https://www.ulbra.br/torres",
                "--", "-", ",",
            "Histórico","Escolar","Nome", "Código "], "", $pagina);
            
            $values = explode("\n", $pagina);

            if($values[6] == " Curso ") {
                $indexAluno++;
                $this->parseTemplatePrimeiraPagina($values, $indexAluno);
            }else {
                $this->parsePaginaNormal($values, $indexAluno);
            }
        }
        unlink($filePath);
        echo $this->preview();
        die;

    }

    public function preview()
    {

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue([1,1], 'Codigo');
        $sheet->setCellValue([2,1], 'Nome');
        $sheet->setCellValue([3,1], 'QTD Disciplinas Cursando');
        $sheet->setCellValue([4,1], 'QTD Disciplinas Concluidas');

        $column = 5;
        foreach($this->arrayDisciplinas as $nomeDisciplina => $estaAtiva) {
            if($estaAtiva) {
                $sheet->setCellValue([$column,1], $nomeDisciplina);
                $column++;
            }
        }

        $row = 2;
        foreach ($this->array as $aluno) {
            $sheet->setCellValue([1,$row], $aluno["codigo"]);
            $sheet->setCellValue([2,$row], $aluno["nome"]);
            $sheet->setCellValue([3,$row], $aluno["total_disciplinas_concluidas"]);
            $sheet->setCellValue([4,$row], $aluno["total_disciplinas_cursando"]);

            $columnValue = 5;
            foreach($this->arrayDisciplinas as $nomeDisciplina  => $estaAtiva) {
                if(isset($aluno["statusPorDisciplina"][$nomeDisciplina])) {
                    $sheet->setCellValue([$columnValue,$row], $aluno["statusPorDisciplina"][$nomeDisciplina]);
                }else{
                    $sheet->setCellValue([$columnValue,$row], "?????");
                }
                $columnValue++;
                
            }
            $row++;
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="arquivo_excel.xlsx"');
        header('Cache-Control: max-age=0');

        // Escrevendo o arquivo diretamente para o PHP output
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    public function parseTemplatePrimeiraPagina($values, $indexAluno) {
        $position = strcspn($values[5], '0123456789');
        $nome = substr($values[5], 0, $position);
        $codigo = substr($values[5], $position);
        $this->array[$indexAluno]['nome'] = trim($nome);
        $this->array[$indexAluno]['codigo'] = trim($codigo);
        $this->array[$indexAluno]['curso'] = trim($values[7]);
        $this->array[$indexAluno]['semestre_atual_proc'] = "1º Semestre";
        $this->array[$indexAluno]['indice_semestre'] = 0;
        $this->array[$indexAluno]['total_disciplinas_concluidas'] = 0;
        $this->array[$indexAluno]['total_disciplinas_cursando'] = 0;
        $this->array[$indexAluno]['disciplinas'] = [];
        $this->array[$indexAluno]['statusPorDisciplina'] = [];

        $key = array_search(" Professor/Titulação", $values);
        $key++;

        for($i = $key; $i < count($values); $i++) {
            $this->adicionaStatusDaDisciplinaParaOAluno($values, $i, $indexAluno);
        }
    }

    public function parsePaginaNormal($values, $indexAluno) {
        foreach($values as $i => $value) {
            $this->adicionaStatusDaDisciplinaParaOAluno($values, $i, $indexAluno);
        }
    }

    public function adicionaStatusDaDisciplinaParaOAluno($values, $i, $indexAluno)
    {
        if(str_contains($values[$i], "º") && !str_contains($values[$i], "Total ") && !str_contains($values[$i], "Forma")) {
            if($this->array[$indexAluno]['semestre_atual_proc'] !== $values[$i]) {
                $this->array[$indexAluno]['semestre_atual_proc'] = $values[$i];
                $this->array[$indexAluno]['indice_semestre'] = $this->array[$indexAluno]['indice_semestre'] + 1;
            }
        }
        
        if(str_contains($values[$i], "Aprovado") ||
            str_contains($values[$i], "Dispensado") ||
            str_contains($values[$i], "A Cursar") ||
            str_contains($values[$i], "Em Curso") ||
            str_contains($values[$i], "Reprovado por Nota") ||
            str_contains($values[$i], "Reprovado por Frequência")) {
                $position = strcspn($values[$i], '0123456789*');
                $indiceSemestre = $this->array[$indexAluno]['indice_semestre'];
                $disciplina = trim(substr($values[$i], 0, $position-1));

                if(str_contains($disciplina, "\t")) {
                    $arrayDisciplina = explode("\t", $disciplina);
                    $disciplina = trim($arrayDisciplina[0]);
                }
                $this->arrayDisciplinas[$disciplina] = true;
                $status = $this->getStatusByValue($values[$i]);

                switch ($status) {
                    case 'Aprovado':
                        $this->array[$indexAluno]['total_disciplinas_concluidas']++;
                        break;
                    case 'Dispensado':
                        $this->array[$indexAluno]['total_disciplinas_concluidas']++;
                        break;
                    case 'Em Curso':
                        $this->array[$indexAluno]['total_disciplinas_cursando']++;
                        break;
                }

                if($status == "Aprovado" || $status == "Dispensado") {
                    $this->array[$indexAluno]['total_disciplinas_concluidas']++;
                }
                $this->array[$indexAluno]['disciplinas'][$indiceSemestre][] = [
                    "disciplina" => $disciplina,
                    "status" => $status
                ];

                $this->array[$indexAluno]['statusPorDisciplina'][$disciplina] = $status;
        }
    }

    

    public function getStatusByValue($value) {
        if(str_contains($value, "Aprovado")) {
            return "Aprovado";
        }
        if(str_contains($value, "Dispensado")) {
            return "Dispensado";
        }

        if(str_contains($value, "A Cursar")) {
            return "A Cursar";
        }

        if(str_contains($value, "Em Curso")) {
            return "Em Curso";
        }

        if(str_contains($value, "Reprovado por Nota")) {
            return "Reprovado por Nota";
        }

        if(str_contains($value, "Reprovado por Frequência")) {
            return "Reprovado por Frequência";
        }
    }
}