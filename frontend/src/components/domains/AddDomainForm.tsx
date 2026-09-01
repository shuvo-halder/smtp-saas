'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import * as z from 'zod';
import api from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { AlertCircle } from 'lucide-react';

const addDomainSchema = z.object({
  domain_name: z.string()
    .min(1, 'Domain name is required')
    .regex(/^[a-zA-Z0-9][a-zA-Z0-9-]{1,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$/, 'Invalid domain format (e.g. example.com)'),
});

type AddDomainFormValues = z.infer<typeof addDomainSchema>;

export function AddDomainForm() {
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<AddDomainFormValues>({
    resolver: zodResolver(addDomainSchema),
  });

  const onSubmit = async (data: AddDomainFormValues) => {
    setError(null);
    try {
      const response = await api.post('/domains', data);
      router.push(`/domains/${response.data.data.id}/setup`);
    } catch (err: any) {
      if (err.response?.data?.message) {
        setError(err.response.data.message);
      } else {
        setError('An unexpected error occurred. Please try again.');
      }
    }
  };

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4 max-w-md">
      {error && (
        <div className="p-3 text-sm text-red-500 bg-red-50 rounded-md flex items-center gap-2">
          <AlertCircle className="h-4 w-4" />
          {error}
        </div>
      )}
      <div className="space-y-2">
        <label className="text-sm font-medium" htmlFor="domain_name">
          Domain Name
        </label>
        <Input 
          id="domain_name" 
          placeholder="example.com" 
          {...register('domain_name')} 
        />
        {errors.domain_name && <p className="text-sm text-red-500">{errors.domain_name.message}</p>}
      </div>
      <Button type="submit" disabled={isSubmitting}>
        {isSubmitting ? <Spinner className="mr-2 h-4 w-4" /> : null}
        Add Domain
      </Button>
    </form>
  );
}
