<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FaqRepository;
use App\Models\GuideRepository;
use App\Services\Auth;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AdminGuideController extends Controller
{
    private const GUIDE_CATEGORIES = ['debuter', 'highlander', 'classes', '6v6', 'config'];
    private const FAQ_CLUSTERS = ['decouverte', 'highlander', 'recrutement', 'apprentissage', 'competition'];

    public function guides(): View
    {
        Auth::requireAdmin();

        return view('admin.guides', [
            'title' => 'Admin - Guides & FAQ',
            'description' => 'Gestion des guides et de la FAQ.',
            'styles' => ['/_css/admin.css'],
            'scripts' => ['/_js/admin_guides.js'],
            'guides' => (new GuideRepository)->all(),
            'faqItems' => (new FaqRepository)->all(),
            'clusters' => FaqRepository::CLUSTERS,
            'categories' => self::GUIDE_CATEGORIES,
        ]);
    }

    public function guideEdit(Request $request): View
    {
        Auth::requireAdmin();

        $id = (int) $request->route('id', 0);
        $guide = $id > 0 ? (new GuideRepository)->find($id) : null;
        if ($id > 0 && $guide === null) {
            abort(404);
        }

        return view('admin.guide_edit', [
            'title' => $guide === null ? 'Admin - Nouveau guide' : 'Admin - Éditer '.$guide['title'],
            'description' => 'Édition d’un guide.',
            'styles' => ['/_css/admin.css', 'https://cdn.jsdelivr.net/npm/easymde@2.20.0/dist/easymde.min.css'],
            'scripts' => ['https://cdn.jsdelivr.net/npm/easymde@2.20.0/dist/easymde.min.js', '/_js/admin_guide_edit.js'],
            'guide' => $guide,
            'categories' => self::GUIDE_CATEGORIES,
        ]);
    }

    public function guideStore(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $data = $this->validateGuide($request);

        $repo = new GuideRepository;
        if ($repo->slugExists($data['slug'])) {
            return back()->withInput()->with('error', 'Ce slug existe déjà.');
        }
        $data['sort_order'] = $data['sort_order'] ?? ($this->maxSort($repo->all()) + 1);
        $repo->create($data);

        return redirect('/admin/guides')->with('success', 'Guide créé.');
    }

    public function guideUpdate(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $id = (int) $request->route('id', 0);
        $repo = new GuideRepository;
        if ($repo->find($id) === null) {
            abort(404);
        }

        $data = $this->validateGuide($request);
        if ($repo->slugExists($data['slug'], $id)) {
            return back()->withInput()->with('error', 'Ce slug existe déjà.');
        }
        $repo->update($id, $data);

        return redirect('/admin/guides')->with('success', 'Guide mis à jour.');
    }

    public function guideDelete(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $id = (int) $request->route('id', 0);
        (new GuideRepository)->delete($id);

        return redirect('/admin/guides')->with('success', 'Guide supprimé.');
    }

    public function guideToggle(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $id = (int) $request->route('id', 0);
        $repo = new GuideRepository;
        $guide = $repo->find($id);
        if ($guide === null) {
            abort(404);
        }
        $guide['is_published'] = ! (int) $guide['is_published'];
        $repo->update($id, $guide);

        return redirect('/admin/guides')->with('success', 'Statut mis à jour.');
    }

    public function faqEdit(Request $request): View
    {
        Auth::requireAdmin();

        $id = (int) $request->route('id', 0);
        $item = $id > 0 ? (new FaqRepository)->find($id) : null;
        if ($id > 0 && $item === null) {
            abort(404);
        }

        return view('admin.faq_edit', [
            'title' => $item === null ? 'Admin - Nouvelle question FAQ' : 'Admin - Éditer la FAQ',
            'description' => 'Édition d’une question FAQ.',
            'styles' => ['/_css/admin.css', 'https://cdn.jsdelivr.net/npm/easymde@2.20.0/dist/easymde.min.css'],
            'scripts' => ['https://cdn.jsdelivr.net/npm/easymde@2.20.0/dist/easymde.min.js', '/_js/admin_guide_edit.js'],
            'item' => $item,
            'clusters' => FaqRepository::CLUSTERS,
        ]);
    }

    public function faqStore(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        (new FaqRepository)->create($this->validateFaq($request));

        return redirect('/admin/guides')->with('success', 'Question FAQ créée.');
    }

    public function faqUpdate(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $id = (int) $request->route('id', 0);
        if ((new FaqRepository)->find($id) === null) {
            abort(404);
        }
        (new FaqRepository)->update($id, $this->validateFaq($request));

        return redirect('/admin/guides')->with('success', 'Question FAQ mise à jour.');
    }

    public function faqDelete(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $id = (int) $request->route('id', 0);
        (new FaqRepository)->delete($id);

        return redirect('/admin/guides')->with('success', 'Question FAQ supprimée.');
    }

    public function faqToggle(Request $request): RedirectResponse
    {
        Auth::requireAdmin();

        $id = (int) $request->route('id', 0);
        $repo = new FaqRepository;
        $item = $repo->find($id);
        if ($item === null) {
            abort(404);
        }
        $item['is_published'] = ! (int) $item['is_published'];
        $repo->update($id, $item);

        return redirect('/admin/guides')->with('success', 'Statut mis à jour.');
    }

    private function validateGuide(Request $request): array
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:128'],
            'title' => ['required', 'string', 'max:191'],
            'meta_description' => ['required', 'string', 'max:300'],
            'category' => ['required', Rule::in(self::GUIDE_CATEGORIES)],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'content_markdown' => ['required', 'string', 'max:200000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_published' => ['sometimes', 'boolean'],
        ]);
        $data['is_published'] = ! empty($data['is_published']) && $data['is_published'] !== '0';

        return $data;
    }

    private function validateFaq(Request $request): array
    {
        $data = $request->validate([
            'cluster' => ['required', Rule::in(self::FAQ_CLUSTERS)],
            'question' => ['required', 'string', 'max:300'],
            'answer_markdown' => ['required', 'string', 'max:20000'],
            'keywords' => ['nullable', 'string', 'max:300'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_published' => ['sometimes', 'boolean'],
        ]);
        $data['is_published'] = ! empty($data['is_published']) && $data['is_published'] !== '0';

        return $data;
    }

    private function maxSort(array $rows): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, (int) ($row['sort_order'] ?? 0));
        }

        return $max;
    }
}
