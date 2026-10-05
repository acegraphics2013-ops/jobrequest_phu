'use client';

import React, { useEffect, useState } from 'react';
import { fetchApi } from '@/lib/api';
import { DocumentationGallery } from '@/components/health-education/DocumentationGallery';
import { FileUploader } from '@/components/health-education/FileUploader';
import { Images, Upload, Search, ChevronLeft, ChevronRight } from 'lucide-react';

export default function VisualDocumentationPage() {
  const [loading, setLoading] = useState(true);
  const [outputs, setOutputs] = useState<any[]>([]);
  const [categories, setCategories] = useState<string[]>([]);
  const [activeCategory, setActiveCategory] = useState('All');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({ totalPages: 1, totalRecords: 0 });
  const [uploadOpen, setUploadOpen] = useState(false);

  const loadOutputs = async (cat = activeCategory, q = search, p = page) => {
    setLoading(true);
    const query = new URLSearchParams({
      category: cat,
      search: q,
      page: String(p),
      limit: '24',
    }).toString();

    const res = await fetchApi(`/health-education/documentation.php?${query}`);
    if (res.success && res.data) {
      setOutputs(res.data.outputs || []);
      setPagination(res.data.pagination);
      if (res.data.categories) {
        setCategories(res.data.categories);
      }
    }
    setLoading(false);
  };

  useEffect(() => {
    loadOutputs();
  }, []);

  const handleCategorySelect = (cat: string) => {
    setActiveCategory(cat);
    setPage(1);
    loadOutputs(cat, search, 1);
  };

  const handleSearchChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value;
    setSearch(value);
    setPage(1);
    loadOutputs(activeCategory, value, 1);
  };

  return (
    <div className="space-y-6">
      {/* Top Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-slate-900">
            Visual Documentation Gallery
          </h1>
          <p className="text-sm text-slate-500">
            Central repository for photo documentation, attendance sheets, presentation slides, and video recordings.
          </p>
        </div>

        <div className="flex items-center gap-3">
          <div className="relative min-w-[220px]">
            <Search className="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
            <input
              type="text"
              placeholder="Search documentation..."
              value={search}
              onChange={handleSearchChange}
              className="w-full rounded-lg border border-slate-300 bg-white pl-9 pr-3.5 py-2 text-xs text-slate-900 focus:border-blue-500"
            />
          </div>

          <button
            onClick={() => setUploadOpen(true)}
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 shrink-0"
          >
            <Upload className="h-4 w-4" />
            Upload Media
          </button>
        </div>
      </div>

      {/* Main Gallery */}
      {loading ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <div key={i} className="h-64 bg-white rounded-xl border border-slate-200 animate-pulse p-4" />
          ))}
        </div>
      ) : (
        <DocumentationGallery
          outputs={outputs}
          onDeleteSuccess={loadOutputs}
          categories={categories}
          activeCategory={activeCategory}
          onSelectCategory={handleCategorySelect}
        />
      )}

      {/* Pagination Controls */}
      {pagination.totalPages > 1 && (
        <div className="flex items-center justify-between border-t border-slate-200 pt-4">
          <p className="text-xs text-slate-500">
            Total <span className="font-bold text-slate-800">{pagination.totalRecords}</span> items
          </p>
          <div className="flex items-center gap-2">
            <button
              onClick={() => {
                const p = page - 1;
                setPage(p);
                loadOutputs(activeCategory, search, p);
              }}
              disabled={page <= 1}
              className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            >
              <ChevronLeft className="h-4 w-4" />
            </button>
            <span className="text-xs font-semibold text-slate-700 px-2">
              Page {page} of {pagination.totalPages}
            </span>
            <button
              onClick={() => {
                const p = page + 1;
                setPage(p);
                loadOutputs(activeCategory, search, p);
              }}
              disabled={page >= pagination.totalPages}
              className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-40"
            >
              <ChevronRight className="h-4 w-4" />
            </button>
          </div>
        </div>
      )}

      {/* Upload Modal (requires session ID selection) */}
      <FileUploader
        isOpen={uploadOpen}
        onClose={() => setUploadOpen(false)}
        sessionId={outputs[0]?.session_id || 1}
        onUploadSuccess={loadOutputs}
      />
    </div>
  );
}
